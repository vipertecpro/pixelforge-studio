<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_nano' => 10_000_000,
            'estimated_max_nano' => 50_000_000,
        ];
    }

    /**
     * Known to Pixelforge, as after a successful create call.
     */
    public function accepted(?string $pixelforgeId = null): static
    {
        return $this->state(fn () => ['pixelforge_id' => $pixelforgeId ?? 'gen_'.fake()->unique()->regexify('[0-9A-Z]{26}')]);
    }

    public function processing(): static
    {
        return $this->accepted()->state(fn () => ['status' => GenerationStatus::Processing]);
    }
}
