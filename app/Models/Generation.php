<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string|null $request_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_nano
 * @property int $estimated_max_nano
 * @property array<string, mixed>|null $rates
 * @property array<string, mixed>|null $usage
 * @property int|null $provider_cost_nano
 * @property int|null $charged_nano
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'request_id', 'status', 'prompt', 'size', 'quality', 'reference_images', 'estimated_min_nano', 'estimated_max_nano'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min_nano' => 'integer',
            'estimated_max_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'charged_nano' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The body sent to POST /v1/generations. The metadata carries our own id,
     * which comes back in every webhook so an event can be matched to this row
     * even if it arrives before Pixelforge's response to the create call.
     *
     * @return array<string, mixed>
     */
    public function providerRequest(): array
    {
        $request = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => ['generation_id' => $this->id],
        ];

        if ($this->reference_images !== []) {
            $request['reference_images'] = $this->reference_images;
        }

        return $request;
    }

    /**
     * Pixelforge's image links stop working 24 hours after completion.
     */
    public function imageIsAvailable(): bool
    {
        return $this->status === GenerationStatus::Succeeded
            && $this->image_url !== null
            && ($this->image_url_expires_at === null || $this->image_url_expires_at->isFuture());
    }
}
