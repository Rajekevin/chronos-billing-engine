<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Model\Event;
use App\Domain\Port\EventRepositoryInterface;
use App\Domain\ValueObject\ClientId;
use App\Infrastructure\Persistence\Doctrine\Entity\EventEntity;
use App\Infrastructure\Persistence\Doctrine\Mapper\EventMapper;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEventRepository implements EventRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventMapper $mapper,
    ) {
    }

    public function save(Event $event): void
    {
        $this->entityManager->persist($this->mapper->toEntity($event));
        $this->entityManager->flush();
    }

    public function findByClientAndPeriod(
        ClientId $clientId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $entities = $this->entityManager
            ->getRepository(EventEntity::class)
            ->createQueryBuilder('e')
            ->where('e.clientId = :clientId')
            ->andWhere('e.timestamp BETWEEN :from AND :to')
            ->setParameter('clientId', $clientId->value())
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        return array_map($this->mapper->toDomain(...), $entities);
    }
}
