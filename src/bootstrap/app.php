<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureApproved;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUiLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', SecurityHeaders::class);
        $middleware->appendToGroup('web', SetUiLocale::class);
        $middleware->trimStrings(except: ['tab_name', 'target_table', 'mappings.*.target_column']);
        $middleware->alias(['approved' => EnsureApproved::class, 'admin' => EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
