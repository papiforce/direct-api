<?php

namespace App\Service;

use App\Entity\Conversation;
use Symfony\Component\HttpFoundation\RequestStack;

class TopicService
{
  public function __construct(private readonly RequestStack $requestStack) {}

  public function getTopicUrl(Conversation $conversation): string
  {
    return "{$this->getServerUrl()}/conversations/{$conversation->getId()}";
  }

  public function getServerUrl(): string
  {
    $request = $this->requestStack->getMainRequest();

    $scheme = $request->getScheme();
    $host = $request->getHost();
    $port = $request->getPort();

    $portUrl = $port ? ":$port" : "";

    return $scheme . '://' . $host . $portUrl;
  }
}
