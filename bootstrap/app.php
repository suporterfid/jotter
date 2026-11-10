<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthorizeWorkspaceAccess;
use App\Http\Middleware\AuthorizeWorkspaceWrite;
use App\Http\Middleware\EnforceCredentialAudience;
use App\Http\Middleware\SetLocaleFromSubject;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Unauthenticated liveness probe registered outside the `api` group on
            // purpose: the session, cookie, and throttle middleware all touch the
            // database, and this route must answer 503 (not 500) when MySQL is down.
            Route::get('/api/health', HealthController::class)->name('health');
        },
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SetLocaleFromSubject::class,
            // Bearer machine/OAuth tokens: scope and audience per route.
            EnforceCredentialAudience::class,
            // JOTTER_2FA_REQUIRED_FOR_ADMINS: /api/admin/* waits for two-step verification.
            \App\Http\Middleware\RequireAdminTwoFactor::class,
        ]);

        // OAuth machine endpoints are called by MCP clients, not by our forms.
        $middleware->validateCsrfTokens(except: ['oauth/register', 'oauth/token', 'oauth/revoke']);

        // Markdown is canonical file content: preserve intentional whitespace and empty notes.
        // MCP tools carry it nested in the JSON-RPC body (params.arguments.content);
        // the write tools hash exactly what they write, so it must arrive untouched.
        $middleware->trimStrings(except: ['content', 'params.arguments.content']);
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request): bool => $request->is('api/workspaces/*/notes*')
                && $request->has('content'),
            fn (Request $request): bool => $request->is('api/mcp'),
        ]);

        $middleware->alias([
            'workspace.authorization' => AuthorizeWorkspaceAccess::class,
            'workspace.write' => AuthorizeWorkspaceWrite::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
