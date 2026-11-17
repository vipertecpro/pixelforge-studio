<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the history page sees of a generation. Money is sent as exact,
 * ready-to-print strings, so the browser never does arithmetic on it.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_min' => Nano::format($this->estimated_min_nano),
            'estimated_max' => Nano::format($this->estimated_max_nano),
            'charged' => $this->charged_nano === null ? null : Nano::format($this->charged_nano),
            'image_url' => $this->imageIsAvailable() ? $this->image_url : null,
            'image_expired' => $this->status === GenerationStatus::Succeeded && ! $this->imageIsAvailable(),
            'error' => $this->error_message,
            'sending' => $this->status === GenerationStatus::Queued && $this->pixelforge_id === null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
