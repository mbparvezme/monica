<?php

namespace Tests\Unit\Domains\Import\Jobs;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProcessImportBatchTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_creates_contacts_and_updates_progress(): void
    {
        $importJob = ImportJob::factory()->create([
            'total_rows'     => 2,
            'processed_rows' => 0,
        ]);

        $rows = [
            ['name' => 'John Doe', 'email' => 'john@example.com'],
            ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        ];

        (new ProcessImportBatch($importJob->id, $rows, 0))->handle();

        $importJob->refresh();
        $this->assertEquals(2, $importJob->processed_rows);
        $this->assertEquals(0, $importJob->failed_rows);
        $this->assertEquals(ImportJob::STATUS_PROCESSING, $importJob->status);
        $this->assertNotNull($importJob->started_at);

        $this->assertDatabaseCount('contacts', 2);
    }

    /** @test */
    public function it_records_error_for_invalid_row_and_continues(): void
    {
        $importJob = ImportJob::factory()->create(['total_rows' => 3]);

        $rows = [
            ['name' => 'John Doe', 'email' => 'john@example.com'],
            ['name' => '',         'email' => ''],                   // invalid — no name
            ['name' => 'Jane Doe', 'email' => 'not-an-email'],       // invalid email
        ];

        (new ProcessImportBatch($importJob->id, $rows, 0))->handle();

        $importJob->refresh();
        $this->assertEquals(1, $importJob->processed_rows);
        $this->assertEquals(2, $importJob->failed_rows);
        $this->assertCount(2, $importJob->errors);
        $this->assertStringContainsString('name', $importJob->errors[0]['message']);
        $this->assertStringContainsString('email', $importJob->errors[1]['message']);
    }

    /** @test */
    public function it_skips_processing_if_import_is_cancelled(): void
    {
        $importJob = ImportJob::factory()->cancelled()->create();

        $rows = [['name' => 'John Doe', 'email' => 'john@example.com']];

        (new ProcessImportBatch($importJob->id, $rows, 0))->handle();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertEquals(0, $importJob->fresh()->processed_rows);
    }
}
