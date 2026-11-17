<?php

namespace App\Http\Resources;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Pricing\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => ($this->amount_nano > 0 ? '+' : '').Nano::format($this->amount_nano, 2),
            'is_charge' => $this->type === CreditTransactionType::Charge,
            'description' => $this->type === CreditTransactionType::Charge && $this->generation !== null
                ? Str::limit($this->generation->prompt, 60)
                : $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
