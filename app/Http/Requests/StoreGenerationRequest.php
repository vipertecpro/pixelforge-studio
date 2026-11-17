<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits, so a request that passes here is not
 * rejected by the API: a prompt of at most 4,000 characters, one of three sizes
 * and qualities, and at most four http(s) reference image links.
 */
class StoreGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Blank rows in the "reference images" list are dropped, not errors.
     */
    protected function prepareForValidation(): void
    {
        $links = $this->input('reference_images');

        if (is_array($links)) {
            $this->merge([
                'reference_images' => array_values(array_filter(
                    array_map(fn (mixed $link): mixed => is_string($link) ? trim($link) : $link, $links),
                    fn (mixed $link): bool => $link !== '' && $link !== null,
                )),
            ]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most '.self::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_images.*.url' => 'Each reference image must be a link starting with http:// or https://.',
        ];
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> */
        return $this->validated('reference_images', []);
    }
}
