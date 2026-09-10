<?php

declare(strict_types=1);

namespace DreamFactory\Core\Agents\Commands;

use DreamFactory\Core\Agents\Models\AgentActivityLedger;
use Illuminate\Console\Command;

/**
 * Drops agent_activity_ledger rows older than the retention window. Same shape
 * as df-mcp-server's `mcp:prune-request-logs`, except the delete is batched:
 * the ledger takes a row per API request, so the first prune on a long-running
 * install can be millions of rows, and a single DELETE that size holds locks
 * and grows the transaction log for the whole run.
 *
 * Batches walk primary-key ranges instead of DELETE ... LIMIT (not supported
 * by sqlsrv, and only by sqlite builds with a compile flag) or whereIn id lists
 * (sqlsrv caps a statement at 2100 bound parameters), so every system DB
 * driver takes the same path.
 *
 * Run manually:  php artisan agents:prune-ledger [--days=N]
 * Run scheduled: daily, registered by the ServiceProvider when
 *                df-agents.ledger.retention_days > 0.
 */
class PruneLedger extends Command
{
    /** Primary-key span covered by one DELETE statement. */
    private const BATCH_SIZE = 5000;

    protected $signature = 'agents:prune-ledger
                            {--days= : Days of ledger rows to retain (default from config, 0 disables pruning)}';

    protected $description = 'Delete agent activity ledger rows older than the retention period';

    public function handle(): int
    {
        $option = $this->option('days');
        $days = ($option === null || $option === '')
            ? (int) config('df-agents.ledger.retention_days', 90)
            : filter_var($option, FILTER_VALIDATE_INT);

        if ($days === false || $days < 0) {
            $this->error('Retention days must be a non-negative integer.');
            return self::INVALID;
        }
        if ($days === 0) {
            $this->info('Ledger retention is disabled (0 days); nothing pruned.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $expired = AgentActivityLedger::query()->where('occurred_at', '<', $cutoff);
        $minId = (clone $expired)->min('id');
        $maxId = (clone $expired)->max('id');

        $deleted = 0;
        if ($minId !== null && $maxId !== null) {
            // occurred_at is re-checked per batch: ids are only roughly in
            // time order, so a range can hold rows that are still in window.
            for ($from = (int) $minId; $from <= (int) $maxId; $from += self::BATCH_SIZE) {
                $deleted += AgentActivityLedger::query()
                    ->where('id', '>=', $from)
                    ->where('id', '<', $from + self::BATCH_SIZE)
                    ->where('occurred_at', '<', $cutoff)
                    ->delete();
            }
        }

        $this->info("Pruned {$deleted} agent activity ledger rows older than {$days} days.");
        return self::SUCCESS;
    }
}
