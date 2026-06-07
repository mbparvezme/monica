<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    public function definition(): array
    {
        return [
            'account_id'     => Account::factory(),
            'user_id'        => User::factory(),
            'vault_id'       => Vault::factory(),
            'filename'       => 'contacts.csv',
            'file_path'      => 'imports/contacts.csv',
            'file_hash'      => hash('sha256', $this->faker->uuid()),
            'total_rows'     => 100,
            'processed_rows' => 0,
            'failed_rows'    => 0,
            'status'         => ImportJob::STATUS_PENDING,
            'errors'         => null,
            'raw_data'       => null,
        ];
    }

    public function processing(): static
    {
        return $this->state([
            'status'     => ImportJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status'         => ImportJob::STATUS_COMPLETED,
            'processed_rows' => 100,
            'started_at'     => now()->subMinutes(2),
            'completed_at'   => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'       => ImportJob::STATUS_FAILED,
            'failed_rows'  => 100,
            'started_at'   => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'       => ImportJob::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }
}
