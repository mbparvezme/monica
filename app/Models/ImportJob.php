<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'account_id',
        'user_id',
        'vault_id',
        'filename',
        'file_path',
        'file_hash',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'status',
        'errors',
        'raw_data',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'raw_data' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    // public function progressPercentage(): int
    // {
    //     if ($this->total_rows === 0) {
    //         return 0;
    //     }

    //     return (int) round(($this->processed_rows / $this->total_rows) * 100);
    // }

    // public function estimatedRemainingSeconds(): ?int
    // {
    //     if (! $this->started_at || $this->processed_rows === 0) {
    //         return null;
    //     }

    //     $elapsed = now()->diffInSeconds($this->started_at);
    //     $rowsRemaining = $this->total_rows - $this->processed_rows;
    //     $secondsPerRow = $elapsed / $this->processed_rows;

    //     return (int) round($rowsRemaining * $secondsPerRow);
    // }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }
}
