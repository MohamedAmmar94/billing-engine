<?php

declare(strict_types=1);

use App\Billing\Http\Controllers\CustomerController;
use App\Billing\Http\Controllers\InvoiceController;
use App\Billing\Http\Controllers\ProductController;
use App\Http\Controllers\TokenController;
use App\Payments\Http\Controllers\PayController;
use App\Shared\Idempotency\IdempotencyMiddleware;
use App\Webhooks\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('webhooks.stripe');
Route::prefix('v1')->group(function (): void {
    Route::post('auth/token', [TokenController::class, 'store'])->middleware('throttle:5,1');
    Route::get('pay/{invoice}', PayController::class)->middleware('throttle:30,1')->name('pay');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/token', [TokenController::class, 'destroy']);

        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);
        Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
        Route::middleware(IdempotencyMiddleware::class)->group(function (): void {

            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::post('invoices/{invoice}/open', [InvoiceController::class, 'open']);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
        });
    });
});
