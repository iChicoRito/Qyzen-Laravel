<?php

use App\Http\Middleware\AjaxFormResponse;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RequireRole::class,
        ]);

        // J1/J2: security headers + CSP nonce on every web response.
        $middleware->web(append: [
            SecurityHeaders::class,
            AjaxFormResponse::class,
            EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Task 32: XHR form posts must fail as JSON. AjaxFormResponse only converts a
        // RedirectResponse, so without this an unhandled exception (a duplicate-key
        // QueryException, a 403, an expired CSRF token) came back as an HTML error page and the
        // submit JS could only say "The server returned an unexpected response".
        //
        // ValidationException is excluded deliberately: it already becomes a redirect-with-errors
        // that AjaxFormResponse turns into the app's own {status, message, errors} shape, and
        // taking it over here would swap that for Laravel's bare {message, errors}.
        //
        // $request->ajax() is the X-Requested-With header the form/table fetches set — the modal
        // loader's fragment GET sends 'fetch' instead and deliberately still gets HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || ($request->ajax() && ! $e instanceof ValidationException),
        );
    })->create();
