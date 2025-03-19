<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

final class MercureController extends AbstractController
{
    #[Route('/api/mercure/token', name: 'api_get_mercure_token', methods: ['GET'])]
    public function getMercureToken(): JsonResponse
    {
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->getParameter('mercure.jwt_secret'))
        );

        $token = $config->builder()
            ->withClaim('mercure', [
                'subscribe' => ['*'],
                'publish' => ['*']
            ])
            ->getToken($config->signer(), $config->signingKey());

        return $this->json(['mercureToken' => $token->toString()]);
    }
}
