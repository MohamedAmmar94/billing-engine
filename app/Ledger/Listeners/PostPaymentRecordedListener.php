<?php

declare(strict_types=1);

namespace App\Ledger\Listeners;

use App\Ledger\Actions\PostPaymentRecordedAction;
use App\Payments\Events\PaymentRecordedEvent;

/** متزامن عن قصد: لازم يشتغل جوه transaction فتح الفاتورة. ما تضيفش ShouldQueue أبداً. */
final class PostPaymentRecordedListener
{
    public function __construct(private readonly PostPaymentRecordedAction $post) {}

    public function handle(PaymentRecordedEvent $event): void
    {
        $this->post->handle($event->payment);
    }
}
