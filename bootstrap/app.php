<?php

declare(strict_types=1);

use App\Billing\Exceptions\InvalidInvoiceException;
use App\Billing\Exceptions\InvoiceStateException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // قواعد البيزنس بتتحول لأكواد HTTP صريحة
        $exceptions->render(fn (InvoiceStateException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidInvoiceException $e) => response()->json(['message' => $e->getMessage()], 422));

    })->create();
