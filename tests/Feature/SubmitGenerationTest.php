<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationSettler;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pf_test_key', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

function runSubmit(Generation $generation, int $attempts = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;
    $job->handle(app(PixelforgeClient::class), app(GenerationSettler::class));

    return $job;
}

/**
 * @return array<string, mixed>
 */
function providerError(string $type, string $code, string $message = 'Nope.'): array
{
    return ['error' => ['type' => $type, 'code' => $code, 'message' => $message]];
}

it('sends the prompt with the generation id as idempotency key and metadata', function () {
    $generation = Generation::factory()->create(['prompt' => 'A red fox', 'size' => '1536x1024', 'quality' => 'high']);
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgePayload('gen_ABC', 'queued', $generation->id), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id)
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request['prompt'] === 'A red fox'
        && $request['size'] === '1536x1024'
        && $request['quality'] === 'high'
        && $request['metadata'] === ['generation_id' => $generation->id]
        && ! isset($request['reference_images']));
    expect($generation->refresh()->pixelforge_id)->toBe('gen_ABC')
        ->and($generation->status)->toBe(GenerationStatus::Queued);
});

it('sends reference images when there are some', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgePayload('gen_ABC', 'queued', $generation->id), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $request) => $request['reference_images'] === ['https://example.com/a.png']);
});

it('does nothing for a generation Pixelforge already knows', function () {
    $generation = Generation::factory()->accepted()->create();
    Http::fake();

    runSubmit($generation);

    Http::assertNothingSent();
});

describe('busy provider', function () {
    it('backs off and tries again on a rate limit', function (string $code, int $status, array $headers) {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(providerError('rate_limit_error', $code), $status, $headers)]);

        $job = runSubmit($generation);

        $job->assertReleased();
        expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(7)
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    })->with([
        'concurrency limit' => ['concurrency_limit_exceeded', 429, ['Retry-After' => '5']],
        'per-minute limit' => ['rate_limit_exceeded', 429, ['Retry-After' => '5']],
    ]);

    it('backs off further after every attempt', function () {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(providerError('api_error', 'overloaded'), 503, ['Retry-After' => '2'])]);

        $first = runSubmit($generation, attempts: 1);
        $fifth = runSubmit($generation, attempts: 5);

        expect($fifth->job->releaseDelay)->toBeGreaterThan($first->job->releaseDelay);
    });

    it('gives up after the last attempt and charges nothing', function (string $type, string $code, int $status) {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(providerError($type, $code), $status)]);

        $job = runSubmit($generation, attempts: 8);

        $job->assertNotReleased();
        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('busy')
            ->and($generation->charged_nano)->toBe(0);
    })->with([
        'overloaded' => ['api_error', 'overloaded', 503],
        'concurrency limit' => ['rate_limit_error', 'concurrency_limit_exceeded', 429],
    ]);
});

describe('refusals', function () {
    it('fails at once when the prompt is rejected, with no retry and no charge', function () {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(providerError('invalid_request_error', 'content_policy_violation'), 400)]);

        $job = runSubmit($generation);

        $job->assertNotReleased();
        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_message)->toContain('content policy')
            ->and($generation->charged_nano)->toBe(0);
        Http::assertSentCount(1);
    });

    it('does not retry a spent daily quota', function () {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(providerError('rate_limit_error', 'daily_limit_exceeded'), 429)]);

        $job = runSubmit($generation);

        $job->assertNotReleased();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_message)->toContain('daily');
    });
});

describe('network trouble', function () {
    it('retries a timeout with the same idempotency key', function () {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => fn () => throw new ConnectionException('timed out')]);

        $job = runSubmit($generation);

        $job->assertReleased();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('does not fail a generation when it cannot tell whether Pixelforge got it', function () {
        $generation = Generation::factory()->create();
        Http::fake(['pixelforge.test/v1/generations' => fn () => throw new ConnectionException('timed out')]);

        $job = runSubmit($generation, attempts: 8);

        $job->assertNotReleased();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });
});

it('fails a generation for good when the job itself blows up', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('boom'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nano)->toBe(0);
});
