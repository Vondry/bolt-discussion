<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Service;

use Bolt\Discussion\Service\VisitorTokenProvider;
use Bolt\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class VisitorTokenProviderTest extends TestCase
{
    private function provider(?Request $request, ?User $user, bool $isEditor = false): VisitorTokenProvider
    {
        $stack = new RequestStack();
        if ($request !== null) {
            $stack->push($request);
        }
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_EDITOR' && $isEditor
        );

        return new VisitorTokenProvider($stack, $security);
    }

    public function testIsModeratorRequiresEditorRole(): void
    {
        $user = $this->createMock(User::class);

        self::assertTrue($this->provider(new Request(), $user, isEditor: true)->isModerator());
        self::assertFalse($this->provider(new Request(), $user, isEditor: false)->isModerator());
        self::assertFalse($this->provider(new Request(), null)->isModerator());
    }

    public function testLoggedInUserTokenIsNamespaced(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        self::assertSame('user:42', $this->provider(new Request(), $user)->getToken());
    }

    public function testValidAnonymousCookieIsNamespacedUnderAnon(): void
    {
        $hex = str_repeat('a1', 16); // 32 hex chars
        $request = new Request(cookies: ['bd_visitor' => $hex]);

        self::assertSame('anon:' . $hex, $this->provider($request, null)->getToken());
    }

    public function testForgedUserCookieCannotImpersonateUserBucket(): void
    {
        // An attacker tries to hijack logged-in user #5's reaction bucket.
        $request = new Request(cookies: ['bd_visitor' => 'user:5']);

        $token = $this->provider($request, null)->getToken();

        self::assertStringStartsWith('anon:', $token);
        self::assertStringNotContainsString('user:5', $token);
    }

    public function testMalformedCookieIsIgnoredAndRegenerated(): void
    {
        $request = new Request(cookies: ['bd_visitor' => '../../etc/passwd']);

        $token = $this->provider($request, null)->getToken();

        self::assertMatchesRegularExpression('/^anon:[a-f0-9]{32}$/', $token);
    }

    public function testLocalStorageHeaderRecoversIdentityWhenCookieMissing(): void
    {
        $vid = str_repeat('ab', 16); // 32 hex chars
        $request = new Request(server: ['HTTP_X_BD_VISITOR' => $vid]);

        $provider = $this->provider($request, null);

        // The header drives the identity …
        self::assertSame('anon:' . $vid, $provider->getToken());

        // … and is re-issued as the cookie so later requests converge on it.
        $response = new Response();
        $provider->applyCookie($response);
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame($vid, $cookies[0]->getValue());
    }

    public function testValidCookieTakesPrecedenceOverHeader(): void
    {
        $cookie = str_repeat('cd', 16);
        $header = str_repeat('ef', 16);
        $request = new Request(cookies: ['bd_visitor' => $cookie], server: ['HTTP_X_BD_VISITOR' => $header]);

        $provider = $this->provider($request, null);

        self::assertSame('anon:' . $cookie, $provider->getToken());
        // Cookie was already valid, so nothing new is issued.
        $response = new Response();
        $provider->applyCookie($response);
        self::assertCount(0, $response->headers->getCookies());
    }

    public function testForgedUserHeaderCannotImpersonate(): void
    {
        $request = new Request(server: ['HTTP_X_BD_VISITOR' => 'user:5']);

        $token = $this->provider($request, null)->getToken();

        self::assertMatchesRegularExpression('/^anon:[a-f0-9]{32}$/', $token);
        self::assertStringNotContainsString('user:5', $token);
    }

    public function testIssuedCookieIsSecureOnHttpsRequests(): void
    {
        $provider = $this->provider(Request::create('https://example.test/discussion'), null);
        $provider->getToken();
        $response = new Response();

        $provider->applyCookie($response);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isSecure());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame('lax', $cookies[0]->getSameSite());
    }
}
