<?php

declare(strict_types=1);

namespace App\Billing\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class InvoiceNumberGeneratorService
{
    /**
     * لازم تتندهش جوه transaction: القفل بيتفك عند آخرها، ولو فشلت الرقم بيرجع.
     */
    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers must be generated inside a transaction.');
        }

        $row = DB::table('sequences')->where('name', 'invoice')->lockForUpdate()->first();

        if ($row === null) {
            throw new RuntimeException('The invoice sequence is missing. Run SequenceSeeder.');
        }

        $next = (int) $row->last_value + 1;

        DB::table('sequences')->where('name', 'invoice')->update(['last_value' => $next]);

        return sprintf('INV-%06d', $next);
    }
}
