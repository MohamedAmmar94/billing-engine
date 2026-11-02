<?php

declare(strict_types=1);

namespace App\Payments\Listeners;

use App\Billing\Events\InvoiceVoidedEvent;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Jobs\ExpireCheckoutSessionJob;

final class CancelLiveLinksOnVoidListener
{
    /**
     * بيشتغل جوه transaction الإلغاء: حالة اللينكات بتتغير مع الفاتورة،
     * وقفل الـ session عند Stripe بيتأجل لحد الـ commit.
     */
    public function handle(InvoiceVoidedEvent $event): void
    {
        $links = $event->invoice->paymentLinks()
            ->whereIn('status', PaymentLinkStatusEnum::liveValues())
            ->get();

        foreach ($links as $link) {
            $link->update(['status' => PaymentLinkStatusEnum::Canceled]);

            if ($link->stripe_session_id !== null) {
                ExpireCheckoutSessionJob::dispatch($link->stripe_session_id)->afterCommit();
            }
        }
    }
}
