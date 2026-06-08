<?php

namespace Tests\Unit\Domains\Import\Services;

use App\Domains\Import\Services\InitiateImport;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InitiateImportTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_creates_an_import_job_and_dispatches_batches(): void
    {
        Bus::fake();

        $user = $this->createUser();
        $vault = $this->createVault($user->account);
        $file = $this->makeCsvFile("name,email\nJohn Doe,john@example.com\nJane Doe,jane@example.com");

        $importJob = (new InitiateImport)->execute([
            'account_id' => $user->account_id,
            'author_id'  => $user->id,
            'vault_id'   => $vault->id,
            'file'       => $file,
        ]);

        $this->assertDatabaseHas('import_jobs', [
            'id'         => $importJob->id,
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
            'vault_id'   => $vault->id,
            'filename'   => 'contacts.csv',
            'total_rows' => 2,
            'status'     => ImportJob::STATUS_PENDING,
        ]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    /** @test */
    public function it_rejects_duplicate_in_progress_import(): void
    {
        Bus::fake();

        $user = $this->createUser();
        $vault = $this->createVault($user->account);
        $csvContent = "name,email\nJohn Doe,john@example.com";
        $hash = hash('sha256', $csvContent);

        ImportJob::factory()->create([
            'account_id' => $user->account_id,
            'file_hash'  => $hash,
            'status'     => ImportJob::STATUS_PROCESSING,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new InitiateImport)->execute([
            'account_id' => $user->account_id,
            'author_id'  => $user->id,
            'vault_id'   => $vault->id,
            'file'       => $this->makeCsvFile($csvContent),
        ]);
    }

    /** @test */
    public function it_dispatches_multiple_batches_for_large_files(): void
    {
        Bus::fake();

        $user = $this->createUser();
        $vault = $this->createVault($user->account);

        $rows = ["name,email"];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = "User {$i},user{$i}@example.com";
        }
        $file = $this->makeCsvFile(implode("\n", $rows));

        $importJob = (new InitiateImport)->execute([
            'account_id' => $user->account_id,
            'author_id'  => $user->id,
            'vault_id'   => $vault->id,
            'file'       => $file,
        ]);

        $this->assertEquals(120, $importJob->total_rows);
        // 120 rows = 3 batches of 50, 50, 20
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3);
    }

    /** @test */
    public function it_fails_if_required_fields_are_missing(): void
    {
        $this->expectException(ValidationException::class);

        (new InitiateImport)->execute(['account_id' => 'not-a-uuid']);
    }

    private function makeCsvFile(string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, 'contacts.csv', 'text/csv', null, true);
    }
}
