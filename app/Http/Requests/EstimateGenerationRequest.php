<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same fields as a real submission, except the prompt may still be empty
 * while the user is typing, so the price can show before they have written it.
 */
class EstimateGenerationRequest extends StoreGenerationRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'prompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
        ];
    }
}
