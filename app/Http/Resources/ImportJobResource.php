<?php

namespace App\Http\Resources;

use App\Helpers\DateHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
            'status' => $this->status,
            'progress_pct' => $this->progressPercentage(),
            'errors' => $this->errors ?? [],
            'started_at' => $this->started_at ? DateHelper::getTimestamp($this->started_at) : null,
            'completed_at' => $this->completed_at ? DateHelper::getTimestamp($this->completed_at) : null,
            'estimated_remaining_sec' => $this->estimatedRemainingSeconds(),
            'created_at' => DateHelper::getTimestamp($this->created_at),
        ];
    }
}
