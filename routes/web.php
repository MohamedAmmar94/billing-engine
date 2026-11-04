<?php

declare(strict_types=1);

use App\Payments\Http\Controllers\PaymentResultController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('payment/{invoice}/success', [PaymentResultController::class, 'success'])->name('payment.success');
    Route::get('payment/{invoice}/cancel', [PaymentResultController::class, 'cancel'])->name('payment.cancel');
});
