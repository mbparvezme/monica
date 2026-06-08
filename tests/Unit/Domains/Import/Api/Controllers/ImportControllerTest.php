<?php

namespace Tests\Unit\Domains\Import\Api\Controllers;

use App\Models\ImportJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

class ImportControllerTest extends ApiTestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_stores_an_import_job(): void
    {
        Bus::fake();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 6, 7));

        $user = $this->createUser(['write']);
        $vault = $this->createVault($user->account);

        $file = $this->makeCsvFile("name,email\nJohn Doe,john@example.com");

        $response = $this->post('/api/import', [
            'file'     => $file,
            'vault_id' => $vault->id,
        ]);

        $response->assertStatus(201);

        $importJob = ImportJob::latest()->first();

        $response->assertJson([
            'data' => [
                'id'             => $importJob->id,
                'filename'       => 'contacts.csv',
                'total_rows'     => 1,
                'processed_rows' => 0,
                'failed_rows'    => 0,
                'status'         => 'pending',
                'progress_pct'   => 0,
            ],
        ]);
    }

    /** @test */
    public function it_rejects_upload_without_a_file(): void
    {
        $this->createUser(['write']);

        $response = $this->post('/api/import', ['vault_id' => 'some-uuid']);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_rejects_duplicate_in_progress_import(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = $this->createUser(['write']);
        $vault = $this->createVault($user->account);
        $csvContent = "name,email\nJohn Doe,john@example.com";

        ImportJob::factory()->create([
            'account_id' => $user->account_id,
            'file_hash'  => hash('sha256', $csvContent),
            'status'     => ImportJob::STATUS_PROCESSING,
        ]);

        $response = $this->post('/api/import', [
            'file'     => $this->makeCsvFile($csvContent),
            'vault_id' => $vault->id,
        ]);

        $response->assertStatus(409);
    }

    /** @test */
    public function it_lists_imports_for_the_authenticated_user(): void
    {
        $user = $this->createUser(['read']);

        ImportJob::factory()->create([
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
            'filename'   => 'my-contacts.csv',
            'status'     => ImportJob::STATUS_COMPLETED,
        ]);

        // another user's import — must not appear
        ImportJob::factory()->create();

        $response = $this->get('/api/import');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.filename', 'my-contacts.csv');
        $response->assertJsonStructure([
            'data' => [['id', 'filename', 'status', 'progress_pct']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }

    /** @test */
    public function it_shows_import_status_with_progress(): void
    {
        $user = $this->createUser(['read']);

        $importJob = ImportJob::factory()->create([
            'account_id'     => $user->account_id,
            'user_id'        => $user->id,
            'total_rows'     => 100,
            'processed_rows' => 50,
            'status'         => ImportJob::STATUS_PROCESSING,
        ]);

        $response = $this->get("/api/import/{$importJob->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.progress_pct', 50);
        $response->assertJsonPath('data.status', 'processing');
    }

    /** @test */
    public function it_cancels_a_pending_import(): void
    {
        $user = $this->createUser(['write']);

        $importJob = ImportJob::factory()->create([
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
            'status'     => ImportJob::STATUS_PENDING,
        ]);

        $response = $this->post("/api/import/{$importJob->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('import_jobs', [
            'id'     => $importJob->id,
            'status' => ImportJob::STATUS_CANCELLED,
        ]);
    }

    /** @test */
    public function it_cannot_cancel_a_completed_import(): void
    {
        $user = $this->createUser(['write']);

        $importJob = ImportJob::factory()->completed()->create([
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
        ]);

        $response = $this->post("/api/import/{$importJob->id}/cancel");

        $response->assertStatus(422);
    }

    /** @test */
    public function it_downloads_error_csv(): void
    {
        $user = $this->createUser(['read']);

        $importJob = ImportJob::factory()->completed()->create([
            'account_id' => $user->account_id,
            'user_id'    => $user->id,
            'errors'     => [
                [
                    'row'     => 2,
                    'data'    => ['name' => 'Bad Row', 'email' => 'not-an-email'],
                    'message' => "Invalid email: 'not-an-email'",
                ],
            ],
        ]);

        $response = $this->get("/api/import/{$importJob->id}/errors.csv");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('name,email,error', $csv);
        $this->assertStringContainsString('Bad Row', $csv);
        $this->assertStringContainsString('not-an-email', $csv);
    }

    private function makeCsvFile(string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, 'contacts.csv', 'text/csv', null, true);
    }
}
