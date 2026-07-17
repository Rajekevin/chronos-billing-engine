<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * OWASP A07 (Identification & Authentication Failures) mitigation:
 * every write endpoint requires a server-side API key.
 *
 * Keys are compared with hash_equals() to prevent timing attacks, and
 * are never logged (see EventController - only a truncated client_id
 * ever hits the logs, never the secret itself).
 *
 * In production, replace the static map below with a lookup against
 * a hashed-key store (DB or Secret Manager), never plaintext keys in code.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly string $expectedApiKeyHash,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-Api-Key');
    }

    public function authenticate(Request $request): Passport
    {
        $providedKey = (string) $request->headers->get('X-Api-Key', '');

        if ($providedKey === '' || !hash_equals($this->expectedApiKeyHash, hash('sha256', $providedKey))) {
            throw new AuthenticationException('Invalid or missing API key.');
        }

        return new SelfValidatingPassport(new UserBadge('api-client', fn () => new InMemoryUser('api-client', null, ['ROLE_API_CLIENT'])));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?Response
    {
        return null; // continue to the controller
    }
}
