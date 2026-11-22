<?php

declare(strict_types=1);

namespace App\Ledger\Console;

use App\Ledger\Services\LedgerIntegrityService;
use Illuminate\Console\Command;

final class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that the books balance and agree with invoices and payments';

    public function handle(LedgerIntegrityService $integrity): int
    {
        $problems = $integrity->problems();

        if ($problems === []) {
            $this->info('The ledger is healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
