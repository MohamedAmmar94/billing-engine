<?php

declare(strict_types=1);

namespace App\Shared\Idempotency\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        $count = DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();

        $this->info("Deleted {$count} expired key(s).");

        return self::SUCCESS;
    }
}
