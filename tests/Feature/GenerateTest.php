<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pf_test_key', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgePricing())]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationInput(array $overrides = []): array
{
    return [
        'prompt' => str_repeat('p', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        ...$overrides,
    ];
}

/**
 * Replace the beforeEach price list with a Pixelforge that answers 500.
 */
function pricingIsDown(): void
{
    Http::swap(new Factory);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response('down', 500)]);
}

describe('access', function () {
    it('sends guests to log in', function () {
        $this->get(route('generations.create'))->assertRedirect(route('login'));
        $this->get(route('generations.index'))->assertRedirect(route('login'));
        $this->post(route('generations.store'), generationInput())->assertRedirect(route('login'));
        $this->postJson(route('generations.estimate'), generationInput())->assertUnauthorized();
    });
});

describe('price before submitting', function () {
    it('shows the exact range, with the markup, from the live price list', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), generationInput())
            ->assertOk()
            ->assertJson([
                // 40 characters is about 10 text tokens (8 to 12), medium 1024x1024 is 1,100
                // output tokens, reasoning is 200 to 2,000: Pixelforge $0.04654 to $0.06906.
                'provider_min' => '$0.04654',
                'provider_max' => '$0.06906',
                // plus 25%
                'min' => '$0.058175',
                'max' => '$0.086325',
                'markup_percent' => 25,
            ]);
    });

    it('prices reference images', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), generationInput(['reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']]))
            ->assertOk()
            ->assertJsonPath('tokens.image', 1530);
    });

    it('answers while the prompt is still empty', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), generationInput(['prompt' => '']))
            ->assertOk();
    });

    it('says so when Pixelforge prices are unreachable and nothing is cached', function () {
        pricingIsDown();

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), generationInput())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Prices are unavailable right now. Please try again in a moment.');
    });
});

describe('submitting', function () {
    it('saves the generation with its estimate and sends it in the background', function () {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), generationInput(['size' => '1536x1024', 'quality' => 'high']))
            ->assertRedirect(route('generations.index'));

        $generation = Generation::sole();
        expect($generation->user_id)->toBe($user->id)
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->pixelforge_id)->toBeNull()
            ->and($generation->size)->toBe('1536x1024')
            ->and($generation->estimated_min_nano)->toBeLessThan($generation->estimated_max_nano)
            ->and($generation->charged_nano)->toBeNull();
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);
    });

    it('drops blank reference rows and keeps real ones', function () {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), generationInput(['reference_images' => ['https://example.com/a.png', '', '  ']]))
            ->assertRedirect();

        expect(Generation::sole()->reference_images)->toBe(['https://example.com/a.png']);
    });

    it('rejects what Pixelforge would reject', function (array $override, string $field) {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), generationInput($override))
            ->assertSessionHasErrors($field);

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no prompt' => [['prompt' => ''], 'prompt'],
        'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'unknown size' => [['size' => '512x512'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
        'reference image that is not a link' => [['reference_images' => ['not a link']], 'reference_images.0'],
        'reference image that is not http' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    ]);

    it('accepts a prompt of exactly 4,000 characters', function () {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), generationInput(['prompt' => str_repeat('a', 4000)]))
            ->assertSessionHasNoErrors();
    });

    it('does not submit when it cannot price the request', function () {
        Queue::fake();
        pricingIsDown();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), generationInput())
            ->assertSessionHasErrors('form');

        expect(Generation::count())->toBe(0);
    });
});

describe('fair use', function () {
    it('stops a user from filling Pixelforge with in-flight images', function () {
        Queue::fake();
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(3)->processing()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), generationInput())
            ->assertSessionHasErrors('form');

        expect(Generation::count())->toBe(3);
        Queue::assertNothingPushed();
    });

    it('lets them submit again once one has finished', function () {
        Queue::fake();
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(2)->processing()->create();
        Generation::factory()->for($user)->accepted()->create(['status' => GenerationStatus::Succeeded, 'charged_nano' => 1]);

        $this->actingAs($user)
            ->post(route('generations.store'), generationInput())
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SubmitGeneration::class);
    });

    it('stops a user at the daily limit', function () {
        Queue::fake();
        config(['pixelforge.limits.per_user_per_day' => 2]);
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(2)->accepted()->create(['status' => GenerationStatus::Failed]);

        $this->actingAs($user)
            ->post(route('generations.store'), generationInput())
            ->assertSessionHasErrors('form');

        Queue::assertNothingPushed();
    });
});

describe('security headers', function () {
    it('sends a strict policy with a nonce on every page', function () {
        $response = $this->get(route('home'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        expect($csp)->toContain("default-src 'self'")
            ->toContain("frame-ancestors 'none'")
            ->toContain("object-src 'none'")
            ->toContain("img-src 'self' data: https://pixelforge.test")
            ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/")
            ->not->toContain('unsafe-eval');
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    });
});

describe('history', function () {
    it('lists only the user\'s own generations, newest first', function () {
        $user = User::factory()->create();
        $older = Generation::factory()->for($user)->create(['created_at' => now()->subHour()]);
        $newer = Generation::factory()->for($user)->create(['created_at' => now()]);
        Generation::factory()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.1.id', $older->id));
    });

    it('shows estimate, charge, status and a usable image link', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->accepted()->create([
            'status' => GenerationStatus::Succeeded,
            'estimated_min_nano' => 58_175_000,
            'estimated_max_nano' => 86_325_000,
            'charged_nano' => 381_887_500,
            'image_url' => 'https://pixelforge.example/a.png',
            'image_url_expires_at' => now()->addHours(3),
        ]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.status', 'succeeded')
                ->where('generations.data.0.estimated_min', '$0.058175')
                ->where('generations.data.0.estimated_max', '$0.086325')
                ->where('generations.data.0.charged', '$0.3818875')
                ->where('generations.data.0.image_url', 'https://pixelforge.example/a.png')
                ->where('generations.data.0.image_expired', false));
    });

    it('hides an image link that has expired', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->accepted()->create([
            'status' => GenerationStatus::Succeeded,
            'charged_nano' => 1,
            'image_url' => 'https://pixelforge.example/a.png',
            'image_url_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.image_url', null)
                ->where('generations.data.0.image_expired', true));
    });

    it('marks a generation Pixelforge has not accepted yet as still being sent', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('generations.data.0.sending', true));
    });
});
