<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorImports extends Command
{
    protected $signature = 'import:monitor';

    protected $description = 'Detect stuck imports and alert on high failure rates';

    private const STUCK_THRESHOLD_MINUTES = 30;

    private const FAILURE_RATE_ALERT_PCT = 20;

    public function handle(): int
    {
        $this->recoverStuckImports();
        $this->checkFailureRate();

        return self::SUCCESS;
    }

    /**
     * Detect imports stuck in "processing" for more than 30 minutes and mark
     * them as failed so the user is not left waiting indefinitely.
     *
     * Query used:
     *   SELECT * FROM import_jobs
     *   WHERE status = 'processing'
     *   AND started_at < NOW() - INTERVAL 30 MINUTE;
     */
    private function recoverStuckImports(): void
    {
        $stuck = ImportJob::where('status', ImportJob::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck imports found.');

            return;
        }

        foreach ($stuck as $importJob) {
            $importJob->update([
                'status'       => ImportJob::STATUS_FAILED,
                'completed_at' => now(),
            ]);

            Log::warning('Import stuck and marked as failed', [
                'import_job_id' => $importJob->id,
                'account_id'    => $importJob->account_id,
                'filename'      => $importJob->filename,
                'started_at'    => $importJob->started_at,
            ]);

            $this->warn("Marked stuck import {$importJob->id} ({$importJob->filename}) as failed.");
        }
    }

    /**
     * Alert if the failure rate across all imports completed in the last hour
     * exceeds 20%.
     *
     * Query used:
     *   SELECT
     *       SUM(failed_rows) / NULLIF(SUM(total_rows), 0) * 100 AS failure_rate_pct
     *   FROM import_jobs
     *   WHERE completed_at >= NOW() - INTERVAL 1 HOUR
     *     AND status IN ('completed', 'failed');
     */
    private function checkFailureRate(): void
    {
        $result = DB::table('import_jobs')
            ->selectRaw('
                SUM(failed_rows) as total_failed,
                SUM(total_rows)  as total_rows,
                SUM(failed_rows) / NULLIF(SUM(total_rows), 0) * 100 as failure_rate_pct
            ')
            ->where('completed_at', '>=', now()->subHour())
            ->whereIn('status', [ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED])
            ->first();

        if (! $result || $result->total_rows === null) {
            $this->info('No completed imports in the last hour.');

            return;
        }

        $rate = round($result->failure_rate_pct, 2);

        $this->info("Failure rate (last 1h): {$rate}% ({$result->total_failed}/{$result->total_rows} rows failed)");

        if ($rate > self::FAILURE_RATE_ALERT_PCT) {
            Log::critical('Import failure rate exceeded threshold', [
                'failure_rate_pct' => $rate,
                'total_failed'     => $result->total_failed,
                'total_rows'       => $result->total_rows,
                'threshold_pct'    => self::FAILURE_RATE_ALERT_PCT,
            ]);

            $this->error("ALERT: failure rate {$rate}% exceeds ".self::FAILURE_RATE_ALERT_PCT.'% threshold.');
        }
    }
}
