<?php

declare(strict_types=1);

namespace App\Ledger\Listeners;

use App\Billing\Events\InvoiceOpenedEvent;
use App\Ledger\Actions\PostInvoiceOpenedAction;

/** متزامن عن قصد: لازم يشتغل جوه transaction فتح الفاتورة. ما تضيفش ShouldQueue أبداً. */
final class PostInvoiceOpenedListener
{
    public function __construct(private readonly PostInvoiceOpenedAction $post) {}

    public function handle(InvoiceOpenedEvent $event): void
    {
        $this->post->handle($event->invoice);
    }
}
