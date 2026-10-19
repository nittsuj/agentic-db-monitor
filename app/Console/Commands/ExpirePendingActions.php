<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PendingAction;
use Illuminate\Console\Command;

class ExpirePendingActions extends Command
{
    protected $signature = 'approvals:expire';

    protected $description = 'Mark pending approvals past their TTL as expired.';

    public function handle(): int
    {
        $expired = PendingAction::expireStale();

        $this->info("Expired {$expired} pending action(s).");

        return self::SUCCESS;
    }
}
