<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Markdown is canonical file content: preserve intentional whitespace and empty notes.
        $middleware->trimStrings(except: ['content']);
        $middleware->convertEmptyStringsToNull(except: [
            fn (\Illuminate\Http\Request $request): bool => $request->is('api/workspaces/*/notes*')
                && $request->has('content'),
        ]);

        $middleware->alias([
            'workspace.authorization' => \App\Http\Middleware\WorkspaceAuthorizationPlaceholder::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
