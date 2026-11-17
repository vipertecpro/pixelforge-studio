<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook event we have received. event_id is unique, so a duplicate delivery
 * can never create a second row.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 */
#[Fillable(['event_id', 'type', 'pixelforge_generation_id', 'payload'])]
class PixelforgeEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
