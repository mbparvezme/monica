<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactInformation;
use App\Models\ContactInformationType;
use App\Models\ImportJob;
use App\Models\Vault;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessImportBatch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $importJobId,
        private array $rows,
        private int $batchIndex,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importJob = ImportJob::find($this->importJobId);

        if (! $importJob || $importJob->status === ImportJob::STATUS_CANCELLED) {
            return;
        }

        $this->markStartedIfFirst($importJob);

        $processedCount = 0;
        $failedCount = 0;
        $errors = [];

        $vault = Vault::find($importJob->vault_id);

        if (! $vault) {
            $importJob->update([
                'status'       => ImportJob::STATUS_FAILED,
                'failed_rows'  => DB::raw('failed_rows + '.count($this->rows)),
                'completed_at' => now(),
            ]);

            return;
        }

        // look up once — reused for every row that has a phone number
        $phoneType = ContactInformationType::where('account_id', $importJob->account_id)
            ->where('type', 'phone')
            ->first();

        foreach ($this->rows as $offset => $row) {
            $rowNumber = ($this->batchIndex * 50) + $offset + 2; // +2: 1-based + header row

            try {
                $this->validateRow($row);
                $this->createContact($row, $vault, $phoneType);
                $processedCount++;
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = [
                    'row'     => $rowNumber,
                    'data'    => $row,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->recordProgress($importJob, $processedCount, $failedCount, $errors);
    }

    private function markStartedIfFirst(ImportJob $importJob): void
    {
        DB::table('import_jobs')
            ->where('id', $importJob->id)
            ->whereNull('started_at')
            ->update([
                'status'     => ImportJob::STATUS_PROCESSING,
                'started_at' => now(),
            ]);
    }

    private function validateRow(array $row): void
    {
        $name = trim($row['first_name'] ?? $row['name'] ?? '');

        if ($name === '') {
            throw new \InvalidArgumentException('Missing required field: name');
        }

        $email = trim($row['email'] ?? '');

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email: '{$email}'");
        }
    }

    private function createContact(array $row, Vault $vault, ?ContactInformationType $phoneType): void
    {
        $parts = explode(' ', trim($row['first_name'] ?? $row['name'] ?? ''), 2);
        [$firstName, $lastName] = [$parts[0], $parts[1] ?? ''];

        $contact = Contact::create([
            'vault_id' => $vault->id,
            'first_name' => $firstName,
            'last_name' => $lastName ?: null,
            'nickname' => $row['nickname'] ?? null,
            'can_be_deleted' => true,
            'listed' => true,
            'template_id' => $vault->default_template_id,
            'last_updated_at' => Carbon::now(),
        ]);

        $phone = trim($row['phone'] ?? '');
        if ($phone !== '' && $phoneType) {
            ContactInformation::create([
                'contact_id' => $contact->id,
                'type_id' => $phoneType->id,
                'data' => $phone,
            ]);
        }
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function recordProgress(ImportJob $importJob, int $processed, int $failed, array $errors): void
    {
        DB::transaction(function () use ($importJob, $processed, $failed, $errors) {
            $current = DB::table('import_jobs')
                ->where('id', $importJob->id)
                ->lockForUpdate()
                ->first();

            if (! $current) {
                return;
            }

            $existingErrors = json_decode($current->errors ?? '[]', true) ?? [];

            DB::table('import_jobs')
                ->where('id', $importJob->id)
                ->update([
                    'processed_rows' => $current->processed_rows + $processed,
                    'failed_rows'    => $current->failed_rows + $failed,
                    'errors'         => json_encode(array_merge($existingErrors, $errors)),
                ]);
        });
    }
}
