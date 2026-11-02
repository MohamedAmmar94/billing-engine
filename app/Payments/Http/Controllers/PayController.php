<?php

declare(strict_types=1);

namespace App\Payments\Http\Controllers;

use App\Billing\Models\Invoice;
use App\Payments\Actions\ResolvePaymentLink;
use App\Payments\Support\PayOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class PayController
{
    public function __invoke(Invoice $invoice, ResolvePaymentLink $resolve): RedirectResponse|JsonResponse
    {
        $resolution = $resolve->handle($invoice);

        return match ($resolution->outcome) {
            PayOutcome::Redirect => redirect()->away((string) $resolution->url),
            PayOutcome::AlreadyPaid => response()->json([
                'status' => 'already_paid',
                'message' => 'This invoice has already been paid.',
            ]),
            PayOutcome::Processing => response()->json([
                'status' => 'processing',
                'message' => 'Your payment is being processed.',
            ]),
            PayOutcome::TryAgain => response()->json([
                'status' => 'try_again',
                'message' => 'Please try again in a few seconds.',
            ], 503, ['Retry-After' => '5']),
            PayOutcome::NotPayable => response()->json([
                'status' => 'not_payable',
                'message' => 'This invoice cannot be paid.',
            ], 409),
            PayOutcome::Unavailable => response()->json([
                'status' => 'unavailable',
                'message' => 'The payment provider could not create a payment page.',
            ], 502),
        };
    }
}
