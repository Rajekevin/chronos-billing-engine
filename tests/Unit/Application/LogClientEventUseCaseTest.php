<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\DTO\LogEventCommand;
use App\Application\UseCase\LogClientEventUseCase;
use App\Domain\Exception\DuplicateEventException;
use App\Domain\Model\Event;
use App\Domain\Port\EventRepositoryInterface;
use App\Domain\Port\IdempotencyGuardInterface;
use PHPUnit\Framework\TestCase;

final class LogClientEventUseCaseTest extends TestCase
{
    public function test_it_persists_a_valid_event_through_the_repository_port(): void
    {
        $repository = $this->createMock(EventRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(Event::class));

        $guard = $this->createMock(IdempotencyGuardInterface::class);
        $guard->method('hasBeenProcessed')->willReturn(false);
        $guard->expects(self::once())->method('markAsProcessed');

        $useCase = new LogClientEventUseCase($repository, $guard);

        $useCase->execute(new LogEventCommand(
            clientId: 'client-00001',
            endpoint: '/api/v1/orders',
            idempotencyKey: 'idem-key-abc',
        ));
    }

    public function test_it_rejects_a_duplicate_event_without_persisting_it_twice(): void
    {
        $repository = $this->createMock(EventRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $guard = $this->createMock(IdempotencyGuardInterface::class);
        $guard->method('hasBeenProcessed')->willReturn(true);
        $guard->expects(self::never())->method('markAsProcessed');

        $useCase = new LogClientEventUseCase($repository, $guard);

        $this->expectException(DuplicateEventException::class);

        $useCase->execute(new LogEventCommand(
            clientId: 'client-00001',
            endpoint: '/api/v1/orders',
            idempotencyKey: 'already-seen-key',
        ));
    }
}
