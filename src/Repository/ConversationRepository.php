<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findConversationBetweenUsers(User $sender, User $recipient): ?Conversation
    {
        return $this->createQueryBuilder('c')->where(':sender MEMBER OF c.users')->andWhere(':recipient MEMBER OF c.users')->setParameter('sender', $sender)->setParameter('recipient', $recipient)->getQuery()->getOneOrNullResult();
    }

    public function save(Conversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
        $this->getEntityManager()->flush();
    }
}
