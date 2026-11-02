<?php

declare(strict_types=1);

namespace App\Payments\Listeners;

use App\Billing\Events\InvoiceVoided;
use App\Payments\Enums\PaymentLinkStatus;
use App\Payments\Jobs\ExpireCheckoutSession;

final class CancelLiveLinksOnVoid
{
    /**
     * بيشتغل جوه transaction الإلغاء: حالة اللينكات بتتغير مع الفاتورة،
     * وقفل الـ session عند Stripe بيتأجل لحد الـ commit.
     */
    public function handle(InvoiceVoided $event): void
    {
        $links = $event->invoice->paymentLinks()
            ->whereIn('status', PaymentLinkStatus::liveValues())
            ->get();

        foreach ($links as $link) {
            $link->update(['status' => PaymentLinkStatus::Canceled]);

            if ($link->stripe_session_id !== null) {
                ExpireCheckoutSession::dispatch($link->stripe_session_id)->afterCommit();
            }
        }
    }
}
