<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ConversationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private ConversationRepository $conversationRepository
    ) {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->conversationRepository = $conversationRepository;
    }

    #[Route('/api/conversation/{recipient}', name: 'api_create_conversation', methods: ['POST'])]
    public function createConversation(?User $recipient): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $sender = $this->getUser();

        $recipientUser = $this->userRepository->find($recipient);

        if (!$recipientUser) {
            return new JsonResponse(['error' => 'Destinataire non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if ($sender === $recipientUser) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas créer une conversation avec vous-même'], Response::HTTP_BAD_REQUEST);
        }

        $existingConversation = $this->conversationRepository->findConversationBetweenUsers($sender, $recipientUser);

        if ($existingConversation) {
            return new JsonResponse([
                'message' => 'La conversation existe déjà',
                'conversation' => [
                    'id' => $existingConversation->getId(),
                    'users' => array_map(function ($user) {
                        return [
                            'id' => $user->getId(),
                            'username' => $user->getUsername(),
                        ];
                    }, $existingConversation->getUsers()->toArray())
                ]
            ], Response::HTTP_OK);
        }

        $conversation = new Conversation();
        $conversation->addUser($sender);
        $conversation->addUser($recipientUser);

        $this->conversationRepository->save($conversation);

        return new JsonResponse([
            'message' => 'Conversation créée avec succès',
            'conversation' => [
                'id' => $conversation->getId(),
                'users' => array_map(function ($user) {
                    return [
                        'id' => $user->getId(),
                        'username' => $user->getUsername(),
                    ];
                }, $conversation->getUsers()->toArray())
            ]
        ], Response::HTTP_CREATED);
    }
}
