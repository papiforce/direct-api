<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
  private $userRepository;

  public function __construct(UserRepository $userRepository)
  {
    $this->userRepository = $userRepository;
  }

  #[Route('/api/users', name: 'api_get_users', methods: ['GET'])]
  public function getUsers(): JsonResponse
  {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    $users = $this->userRepository->findBy([], ['username' => 'ASC']);

    $filteredUsers = array_filter($users, function ($user) use ($users) {
      return $user->getUsername() !== $this->getUser()->getUserIdentifier();
    });

    $usersArray = [];
    foreach ($filteredUsers as $user) {
      $usersArray[] = [
        'id' => $user->getId(),
        'username' => $user->getUsername(),
      ];
    }

    return new JsonResponse($usersArray);
  }

  /**
   * @method User|null getUser()
   */
  #[Route('/api/user/me', name: 'api_get_current_user', methods: ['GET'])]
  public function getCurrentUser(): JsonResponse
  {
    $userInterface = $this->getUser();

    if (!$userInterface) {
      return new JsonResponse(['error' => 'Utilisateur non connecté'], 401);
    }

    if ($userInterface instanceof User) {
      $user = $userInterface;
      return new JsonResponse([
        'id' => $user->getId(),
        'username' => $user->getUsername(),
        'roles' => $user->getRoles()
      ]);
    }

    return new JsonResponse([
      'id' => $userInterface->getUserIdentifier(),
      'username' => $userInterface->getUserIdentifier(),
      'roles' => $userInterface->getRoles()
    ]);
  }
}
