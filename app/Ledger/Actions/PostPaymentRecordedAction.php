<?php

declare(strict_types=1);

namespace App\Ledger\Actions;

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Services\LedgerService;
use App\Ledger\Support\JournalEntryDTO;
use App\Ledger\Support\JournalLineDTO;
use App\Payments\Models\Payment;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class PostPaymentRecordedAction
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * مدين: Gateway Clearing. دائن: المدينين لو الدفع اتطبّق على الفاتورة،
     * أو Unapplied Payments لو لأ (التزام علينا لحد ما نرجّع الفلوس).
     */
    public function handle(Payment $payment): ?JournalEntry
    {
        // الدفتر بعملة واحدة: غير كده بيتسجل في payments بس، وبنكتب تحذير للمراجعة
        if ($payment->currency !== config()->string('billing.currency') || $payment->amount <= 0) {
            Log::warning('Payment was not posted to the ledger (unsupported currency or amount)', [
                'payment' => $payment->uuid,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
            ]);

            return null;
        }

        $currency = CurrencyEnum::from($payment->currency);
        $amount = Money::of($payment->amount, $currency);
        $credited = $payment->is_applied ? AccountCodeEnum::AccountsReceivable : AccountCodeEnum::UnappliedPayments;

        return $this->ledger->post(new JournalEntryDTO(
            description: $payment->is_applied ? 'Payment received' : 'Payment received, not applied to an invoice',
            postedAt: CarbonImmutable::instance($payment->paid_at),
            currency: $currency,
            sourceType: 'payment',
            sourceId: $payment->id,
            event: 'recorded',
            lines: [
                JournalLineDTO::debit(AccountCodeEnum::GatewayClearing, $amount),
                JournalLineDTO::credit($credited, $amount),
            ],
        ));
    }
}
