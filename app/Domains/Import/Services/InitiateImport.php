<?php

namespace App\Domains\Import\Services;

use App\Interfaces\ServiceInterface;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportJob;
use App\Services\BaseService;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Throwable;

class InitiateImport extends BaseService implements ServiceInterface
{
    private ImportJob $importJob;

    private array $data;

    public function rules(): array
    {
        return [
            'account_id' => 'required|uuid|exists:accounts,id',
            'author_id' => 'required|uuid|exists:users,id',
            'vault_id' => 'required|uuid|exists:vaults,id',
        ];
    }

    public function permissions(): array
    {
        return [
            'author_must_belong_to_account',
        ];
    }

    public function execute(array $data): ImportJob
    {
        $this->validateRules($data);
        $this->data = $data;

        /** @var UploadedFile $file */
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());

        $this->abortIfDuplicateInProgress($hash);

        $rows = $this->parseCsv($file); // Only CSV parsing is supported for now.
        $path = $file->store('imports', 'local');

        $this->createImportJob($file->getClientOriginalName(), $path, $hash, $rows);
        $this->dispatchBatches($rows);

        return $this->importJob;
    }

    private function abortIfDuplicateInProgress(string $hash): void
    {
        $exists = ImportJob::where('account_id', $this->data['account_id'])
            ->where('file_hash', $hash)
            ->whereIn('status', [ImportJob::STATUS_PENDING, ImportJob::STATUS_PROCESSING])
            ->exists();

        if ($exists) {
            abort(409, 'An import with this file is already in progress.');
        }
    }

    private function parseCsv(UploadedFile $file): array
    {
        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map('trim', $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (array_filter($line)) {
                $rows[] = array_combine($headers, array_pad($line, count($headers), null));
            }
        }

        fclose($handle);

        return $rows;
    }

    private function createImportJob(string $filename, string $path, string $hash, array $rows): void
    {
        $this->importJob = ImportJob::create([
            'account_id' => $this->data['account_id'],
            'user_id' => $this->data['author_id'],
            'vault_id' => $this->data['vault_id'],
            'filename' => $filename,
            'file_path' => $path,
            'file_hash' => $hash,
            'total_rows' => count($rows),
            'processed_rows' => 0,
            'failed_rows' => 0,
            'status' => ImportJob::STATUS_PENDING,
            'raw_data' => $rows,
        ]);
    }

    private function dispatchBatches(array $rows): void
    {
        $importJobId = $this->importJob->id;

        $jobs = collect(array_chunk($rows, 50))
            ->map(fn ($chunk, $index) => new ProcessImportBatch($importJobId, $chunk, $index))
            ->all();

        Bus::batch($jobs)
            ->then(function (Batch $batch) use ($importJobId) {
                $job = ImportJob::find($importJobId);

                if (! $job || $job->status === ImportJob::STATUS_CANCELLED) {
                    return;
                }

                $allFailed = $job->processed_rows === 0 && $job->failed_rows > 0
                    && $job->failed_rows === $job->total_rows;

                $job->update([
                    'status' => $allFailed ? ImportJob::STATUS_FAILED : ImportJob::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($importJobId) {
                ImportJob::where('id', $importJobId)
                    ->whereNotIn('status', [ImportJob::STATUS_CANCELLED])
                    ->update([
                        'status' => ImportJob::STATUS_FAILED,
                        'completed_at' => now(),
                    ]);
            })
            ->onQueue('imports')
            ->dispatch();
    }
}
