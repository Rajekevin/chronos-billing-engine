<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Domain\Exception\DuplicateEventException;
use App\Domain\Exception\InvalidClientIdException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * OWASP A05 (Security Misconfiguration) / A09 (Logging failures) mitigation:
 * translates Domain exceptions into clean, minimal JSON error responses
 * and ensures the *real* exception (with stack trace) only ever goes to
 * structured logs - never to the HTTP response body, even in dev misconfig.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        [$status, $message] = match (true) {
            $throwable instanceof InvalidClientIdException => [422, 'Invalid request payload.'],
            $throwable instanceof DuplicateEventException => [409, 'Event already processed.'],
            // Missing/invalid auth without a header for ApiKeyAuthenticator::supports()
            // to catch bypasses the authenticator entirely and lands here as a plain
            // security exception - still a 401, never a generic 500.
            $throwable instanceof AuthenticationException => [401, 'Unauthorized'],
            $throwable instanceof HttpExceptionInterface => [$throwable->getStatusCode(), match (true) {
                $throwable->getStatusCode() === 401 => 'Unauthorized',
                $throwable->getStatusCode() === 403 => 'Forbidden',
                $throwable->getStatusCode() === 404 => 'Not found.',
                $throwable->getStatusCode() < 500 => 'Bad request.',
                default => 'Internal server error.',
            }],
            default => [500, 'Internal server error.'],
        };

        $this->logger->error('chronos.request.exception', [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'status' => $status,
        ]);

        $event->setResponse(new JsonResponse(['error' => $message], $status));
    }
}
