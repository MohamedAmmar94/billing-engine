<?php

declare(strict_types=1);

namespace App\Payments\Jobs;

use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

final class ExpireCheckoutSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    // الـ Job واللسنر: إلغاء اللينكات عند الـ void
    public function __construct(public readonly string $sessionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(PaymentGatewayInterface $gateway): void
    {
        try {
            $gateway->expireCheckoutSession($this->sessionId);
        } catch (GatewayTimeoutException $e) {
            throw $e; // نعيد المحاولة
        } catch (GatewayException $e) {
            // مش مفتوحة أصلاً (اتدفعت أو انتهت)، مفيش حاجة نعملها
            Log::info('Checkout session was not expired', ['session' => $this->sessionId, 'reason' => $e->getMessage()]);
        }
    }
}
