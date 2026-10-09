<?php

namespace MlSolutions\NovaLogsView\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MlSolutions\NovaLogsView\Http\Middleware\Authorize;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuthorizationTest extends TestCase
{
    public function test_guests_are_denied(): void
    {
        $this->expectException(HttpException::class);
        (new Authorize)->handle(Request::create('/'), fn () => new Response('allowed'));
    }

    public function test_undefined_gate_denies_authenticated_users(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => new User);
        $this->expectException(HttpException::class);
        (new Authorize)->handle($request, fn () => new Response('allowed'));
    }

    public function test_only_explicitly_authorized_users_can_read_logs(): void
    {
        Gate::define('viewNovaLogs', fn ($user) => $user->getAuthIdentifier() === 1);
        $request = Request::create('/');
        $user = new User;
        $user->forceFill(['id' => 1]);
        $request->setUserResolver(fn () => $user);
        $this->assertSame('allowed', (new Authorize)->handle($request, fn () => new Response('allowed'))->getContent());
        $user->forceFill(['id' => 2]);
        try {
            (new Authorize)->handle($request, fn () => new Response('allowed'));
            $this->fail('Unauthorized user was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
