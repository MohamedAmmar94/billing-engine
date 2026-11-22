<?php

declare(strict_types=1);

namespace App\Ledger\Listeners;

use App\Billing\Events\InvoiceVoidedEvent;
use App\Ledger\Actions\PostInvoiceVoidedAction;

/** متزامن عن قصد: لازم يشتغل جوه transaction فتح الفاتورة. ما تضيفش ShouldQueue أبداً. */
final class PostInvoiceVoidedListener
{
    public function __construct(private readonly PostInvoiceVoidedAction $post) {}

    public function handle(InvoiceVoidedEvent $event): void
    {
        $this->post->handle($event->invoice);
    }
}
