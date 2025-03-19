<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Entity\Conversation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ConversationRepository;
use App\Service\TopicService;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @method User|null getUser()
 */
final class MessageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversationRepository,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly TopicService $topicService,
        private readonly HubInterface $hub
    ) {}

    #[Route('/conversations/{conversationId}/messages', name: 'api_conversation_messages', methods: ['GET'])]
    public function getConversationMessages(Conversation $conversationId): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation) {
            return new JsonResponse([
                'error' => 'Conversation non trouvée'
            ], Response::HTTP_NOT_FOUND);
        }

        $messages = $this->entityManager->getRepository(Message::class)
            ->findBy(['conversation' => $conversationId], ['createdAt' => 'ASC']);

        $data = [];
        foreach ($messages as $message) {
            $data[] = [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'author' => [
                    'id' => $message->getAuthor()->getId(),
                    'username' => $message->getAuthor()->getUsername()
                ],
                'conversation' => [
                    'id' => $conversation->getId()
                ],
                'image' => $message->getImage(),
                'isLiked' => $message->getIsLiked(),
                'createdAt' => $message->getCreatedAt()
            ];
        }

        return new JsonResponse([
            'messages' => $data,
        ], Response::HTTP_OK);
    }

    #[Route('/api/message/{conversation}/{message}/toggle-like', name: 'api_message_toggle_like', methods: ['PUT'])]
    public function toggleLike(
        Conversation $conversation,
        Message $message,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $currentUser = $this->getUser();

        if ($message->getAuthor()->getUsername() === $currentUser->getUserIdentifier()) {
            return new JsonResponse([
                'error' => 'Vous n\'êtes pas autorisé à aimer ce message'
            ], Response::HTTP_FORBIDDEN);
        }

        $newLikeStatus = $message->toggleLike();

        $entityManager->persist($message);
        $entityManager->flush();

        $data = [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'conversation' => [
                'id' => $conversation->getId()
            ],
            'isLiked' => $newLikeStatus,
            'image' => $message->getImage(),
            'createdAt' => $message->getCreatedAt()
        ];

        $topic = $this->topicService->getTopicUrl($conversation);

        $update = new Update(
            $topic,
            json_encode($data),
            private: true
        );

        $this->hub->publish($update);

        return new JsonResponse([
            'message' => 'Message aimé avec succès',
            'data' => $data
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/message/image', name: 'api_send_message_image', methods: ['POST'])]
    public function sendMessageWithImage(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $author = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['conversationId'])) {
            return new JsonResponse([
                'error' => 'L\'ID de la conversation est requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        $conversation = $this->conversationRepository->find($data['conversationId']);

        if (!$conversation) {
            return new JsonResponse([
                'error' => 'Conversation non trouvée'
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isUserInConversation($author, $conversation)) {
            return new JsonResponse([
                'error' => 'Vous n\'êtes pas autorisé à envoyer un message dans cette conversation'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!isset($data['image'])) {
            return new JsonResponse([
                'error' => 'Aucune image n\'a été fournie'
            ], Response::HTTP_BAD_REQUEST);
        }

        $imageBase64 = $data['image'];

        $imageBase64 = str_replace('data:image/jpeg;base64,', '', $imageBase64);
        $imageBase64 = str_replace('data:image/png;base64,', '', $imageBase64);
        $imageBase64 = str_replace('data:image/gif;base64,', '', $imageBase64);

        $imageBinary = base64_decode($imageBase64);

        $f = finfo_open();
        $type = finfo_buffer($f, $imageBinary, FILEINFO_MIME_TYPE);
        finfo_close($f);

        if (!in_array($type, ['image/jpeg', 'image/png', 'image/gif'])) {
            return new JsonResponse([
                'error' => 'Le type d\'image n\'est pas pris en charge'
            ], Response::HTTP_BAD_REQUEST);
        }

        $filename = uniqid('image_', true) . '.' . explode('/', $type)[1];

        $path = $this->getParameter('kernel.project_dir') . '/public/images/' . $filename;
        file_put_contents($path, $imageBinary);

        $message = new Message();
        $message->setContent($data['content'] ?? '');
        $message->setAuthor($author);
        $message->setConversation($conversation);
        $message->setCreatedAt(new \DateTimeImmutable());
        $message->setImage($filename);

        $errors = $this->validator->validate($message);

        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }

            return new JsonResponse([
                'errors' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $data = [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'author' => [
                'id' => $author->getId(),
                'username' => $author->getUsername()
            ],
            'conversation' => [
                'id' => $conversation->getId()
            ],
            'createdAt' => $message->getCreatedAt(),
            'image' => $filename
        ];

        $topic = $this->topicService->getTopicUrl($conversation);

        $update = new Update(
            $topic,
            json_encode($data),
            private: true
        );

        $this->hub->publish($update);

        return new JsonResponse([
            'message' => 'Message envoyé avec succès',
            'data' => $data
        ], Response::HTTP_CREATED);
    }


    #[Route('/api/message', name: 'api_send_message', methods: ['POST'])]
    public function sendMessage(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $author = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['content']) || !isset($data['conversationId'])) {
            return new JsonResponse([
                'error' => 'Le contenu du message et l\'ID de la conversation sont requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        $conversation = $this->conversationRepository->find($data['conversationId']);

        if (!$conversation) {
            return new JsonResponse([
                'error' => 'Conversation non trouvée'
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isUserInConversation($author, $conversation)) {
            return new JsonResponse([
                'error' => 'Vous n\'êtes pas autorisé à envoyer un message dans cette conversation'
            ], Response::HTTP_FORBIDDEN);
        }

        $message = new Message();
        $message->setContent($data['content']);
        $message->setAuthor($author);
        $message->setConversation($conversation);
        $message->setCreatedAt(new \DateTimeImmutable());

        $errors = $this->validator->validate($message);

        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }

            return new JsonResponse([
                'errors' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $data = [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'author' => [
                'id' => $author->getId(),
                'username' => $author->getUsername()
            ],
            'conversation' => [
                'id' => $conversation->getId()
            ],
            'image' =>  $message->getImage(),
            'createdAt' => $message->getCreatedAt()
        ];

        $topic = $this->topicService->getTopicUrl($conversation);

        $update = new Update(
            $topic,
            json_encode($data),
            private: true
        );

        $this->hub->publish($update);

        return new JsonResponse([
            'message' => 'Message envoyé avec succès',
            'data' => $data
        ], Response::HTTP_CREATED);
    }

    private function isUserInConversation(User $user, Conversation $conversation): bool
    {
        foreach ($conversation->getUsers() as $participantUser) {
            if ($participantUser->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
