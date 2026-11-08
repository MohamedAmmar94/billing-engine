<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Events\InvoiceVoidedEvent;
use App\Payments\Gateway\Fake\FakeGateway;
use App\Payments\Gateway\PaymentGatewayInterface;
use App\Payments\Gateway\Stripe\StripeGateway;
use App\Payments\Listeners\CancelLiveLinksOnVoidListener;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // $this->app->singleton(PaymentGatewayInterface::class, FakeGateway::class);
        $this->app->singleton(PaymentGatewayInterface::class, fn (): PaymentGatewayInterface => match (config()->string('payments.gateway')) {
            'stripe' => new StripeGateway,
            'fake' => new FakeGateway,
            default => throw new InvalidArgumentException('Unknown payment gateway configured.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Event::listen(InvoiceVoidedEvent::class, CancelLiveLinksOnVoidListener::class);

    }
}
