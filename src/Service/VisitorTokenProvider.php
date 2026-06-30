<?php

declare(strict_types=1);

namespace Bolt\Discussion\Service;

use Bolt\Entity\User;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the current visitor for reaction de-duplication:
 *  - logged-in Bolt users are identified by "user:<id>"
 *  - anonymous visitors get an opaque, long-lived "bd_visitor" cookie token
 */
class VisitorTokenProvider
{
    private const COOKIE_NAME = 'bd_visitor';
    private const HEADER_NAME = 'X-BD-Visitor';

    private ?string $token = null;
    private ?string $issuedAnonymousToken = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function getToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $this->token = 'user:' . $user->getId();
        }

        // Anonymous visitors are identified by a 32-char hex id, namespaced under
        // "anon:" so a forged value can never collide with the "user:<id>"
        // namespace and impersonate a logged-in user's bucket.
        $request = $this->requestStack->getCurrentRequest();

        // 1) Server-issued cookie (preferred): consistent for the server-rendered
        //    first page, and set for a year.
        $cookie = $request?->cookies->get(self::COOKIE_NAME);
        if (is_string($cookie) && $this->isValidId($cookie)) {
            return $this->token = 'anon:' . $cookie;
        }

        // 2) The visitor's localStorage id, sent as a header. Lets a returning
        //    visitor's identity — and so their "did I react?" state — survive even
        //    if the cookie was cleared. We re-issue it as the cookie to converge.
        $header = $request?->headers->get(self::HEADER_NAME);
        if (is_string($header) && $this->isValidId($header)) {
            $this->issuedAnonymousToken = $header;

            return $this->token = 'anon:' . $header;
        }

        // 3) Brand-new visitor: mint a fresh id (persisted via the cookie).
        $this->issuedAnonymousToken = bin2hex(random_bytes(16));

        return $this->token = 'anon:' . $this->issuedAnonymousToken;
    }

    /**
     * Persist a freshly-issued anonymous token onto the response, if any. No-op
     * for logged-in users or returning visitors.
     */
    public function applyCookie(Response $response): void
    {
        if ($this->issuedAnonymousToken === null) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $secure = $request?->isSecure();
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME, $this->issuedAnonymousToken)
                ->withExpires(new DateTimeImmutable('+1 year'))
                ->withSecure($secure !== false)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX)
        );
    }

    private function isValidId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $value) === 1;
    }

    public function isLoggedIn(): bool
    {
        return $this->security->getUser() instanceof User;
    }

    /**
     * Whether the current user may moderate (delete / approve / see pending).
     * Requires an editor-level Bolt role, matching the backend admin screen.
     */
    public function isModerator(): bool
    {
        return $this->security->isGranted('ROLE_EDITOR');
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
