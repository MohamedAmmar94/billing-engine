<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Events\InvoiceVoided;
use App\Payments\Gateway\Fake\FakeGateway;
use App\Payments\Gateway\PaymentGateway;
use App\Payments\Listeners\CancelLiveLinksOnVoid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, FakeGateway::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Event::listen(InvoiceVoided::class, CancelLiveLinksOnVoid::class);

    }
}
