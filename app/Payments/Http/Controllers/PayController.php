<?php

declare(strict_types=1);

namespace App\Payments\Http\Controllers;

use App\Billing\Models\Invoice;
use App\Payments\Actions\ResolvePaymentLinkAction;
use App\Payments\Support\PayOutcomeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class PayController
{
    public function __invoke(Invoice $invoice, ResolvePaymentLinkAction $resolve): RedirectResponse|JsonResponse
    {
        $resolution = $resolve->handle($invoice);

        return match ($resolution->outcome) {
            PayOutcomeEnum::Redirect => redirect()->away((string) $resolution->url),
            PayOutcomeEnum::AlreadyPaid => response()->json([
                'status' => 'already_paid',
                'message' => 'This invoice has already been paid.',
            ]),
            PayOutcomeEnum::Processing => response()->json([
                'status' => 'processing',
                'message' => 'Your payment is being processed.',
            ]),
            PayOutcomeEnum::TryAgain => response()->json([
                'status' => 'try_again',
                'message' => 'Please try again in a few seconds.',
            ], 503, ['Retry-After' => '5']),
            PayOutcomeEnum::NotPayable => response()->json([
                'status' => 'not_payable',
                'message' => 'This invoice cannot be paid.',
            ], 409),
            PayOutcomeEnum::Unavailable => response()->json([
                'status' => 'unavailable',
                'message' => 'The payment provider could not create a payment page.',
            ], 502),
        };
    }
}
