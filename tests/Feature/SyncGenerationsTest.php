<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationSettler;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pf_test_key', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

it('settles a generation whose webhook never arrived', function () {
    $generation = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response(
        pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id)
    )]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nano)->toBe(381_887_500);
});

it('charges once when a webhook arrives after the poll already settled it', function () {
    $generation = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    $payload = pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id);
    Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response($payload)]);

    $this->artisan('generations:sync');

    // The late webhook, even if it somehow carried different numbers, can not rewrite the money.
    $lateWebhook = pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id);
    $lateWebhook['usage']['output_tokens'] = 1;
    app(GenerationSettler::class)->apply($lateWebhook);

    expect($generation->refresh()->charged_nano)->toBe(381_887_500)
        ->and($generation->provider_cost_nano)->toBe(305_510_000);
});

it('leaves recently active generations alone', function () {
    Generation::factory()->processing()->create(['updated_at' => now()->subSeconds(10)]);
    Http::fake();

    $this->artisan('generations:sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('keeps going when Pixelforge cannot be polled', function () {
    $generation = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake(["pixelforge.test/v1/generations/{$generation->pixelforge_id}" => Http::response('down', 500)]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('re-sends a generation Pixelforge never accepted, with the same id', function () {
    Queue::fake();
    $generation = Generation::factory()->create(['updated_at' => now()->subMinutes(15), 'created_at' => now()->subMinutes(15)]);

    $this->artisan('generations:sync')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);
});

it('gives up on a generation that could not be sent for an hour', function () {
    Queue::fake();
    $generation = Generation::factory()->create(['updated_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0);
    Queue::assertNothingPushed();
});
