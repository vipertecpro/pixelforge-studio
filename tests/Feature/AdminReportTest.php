<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\PixelforgeEvent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pf_test_key', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/**
 * Two users, two UTC days: day one has two images (cost 0.40551), day two has one (cost 0.05).
 *
 * @return array{ann: User, bob: User}
 */
function reportFixtures(): array
{
    $ann = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $succeeded = fn (User $user, int $cost, int $charged, string $completedAt) => Generation::factory()->for($user)->accepted()->create([
        'status' => GenerationStatus::Succeeded,
        'provider_cost_nano' => $cost,
        'charged_nano' => $charged,
        'completed_at' => $completedAt,
    ]);

    $succeeded($ann, 305_510_000, 381_887_500, '2026-11-16 10:00:00');
    $succeeded($bob, 100_000_000, 125_000_000, '2026-11-16 23:59:59');
    $succeeded($ann, 50_000_000, 62_500_000, '2026-11-17 00:00:01');

    // Never part of the report: not succeeded, or outside the dates.
    Generation::factory()->for($ann)->accepted()->create(['status' => GenerationStatus::Failed, 'provider_cost_nano' => 0, 'charged_nano' => 0, 'completed_at' => '2026-11-16 12:00:00']);
    Generation::factory()->for($ann)->processing()->create();
    $succeeded($ann, 999_000_000, 999_000_000, '2026-10-01 12:00:00');

    return ['ann' => $ann, 'bob' => $bob];
}

/**
 * @param  list<array{date: string, generations: int, cost_usd: string}>  $days
 */
function fakePixelforgeUsage(array $days): void
{
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => $days,
        'total' => ['generations' => array_sum(array_column($days, 'generations')), 'cost_usd' => '0'],
    ])]);
}

const REPORT_DATES = ['from' => '2026-11-16', 'to' => '2026-11-17'];

describe('access', function () {
    it('is for operators only', function () {
        $this->get(route('admin.report'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertOk();
    });

    it('is not offered to people who sign up', function () {
        $this->post(route('register.store'), [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'is_admin' => true,
        ]);

        expect(User::where('email', 'sneaky@example.com')->firstOrFail()->is_admin)->toBeFalse();
    });
});

describe('by day', function () {
    it('totals charges, Pixelforge cost and profit for each UTC day, newest first', function () {
        reportFixtures();
        fakePixelforgeUsage([
            ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.405510000'],
            ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.050000000'],
        ]);

        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->has('days', 2)
            ->where('days.0.day', '2026-11-17')
            ->where('days.0.generations', 1)
            ->where('days.0.charged', '$0.0625')
            ->where('days.0.provider_cost', '$0.0500')
            ->where('days.0.profit', '$0.0125')
            ->where('days.1.day', '2026-11-16')
            ->where('days.1.generations', 2)
            ->where('days.1.charged', '$0.5068875')
            ->where('days.1.provider_cost', '$0.40551')
            ->where('days.1.profit', '$0.1013775')
            ->where('totals.generations', 3)
            ->where('totals.charged', '$0.5693875')
            ->where('totals.provider_cost', '$0.45551')
            ->where('totals.profit', '$0.1138775'));
    });

    it('shows each day agreeing with Pixelforge\'s own usage report', function () {
        reportFixtures();
        fakePixelforgeUsage([
            ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.405510000'],
            ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.050000000'],
        ]);

        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.matches', true)
            ->where('days.0.pixelforge_cost', '$0.0500')
            ->where('days.1.matches', true)
            ->where('totals.reconciliation', 'match')
            ->where('totals.pixelforge_cost', '$0.45551'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-11-16')
            && str_contains($request->url(), 'end_date=2026-11-17')
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    });

    it('flags a day where our records and Pixelforge\'s differ', function () {
        reportFixtures();
        fakePixelforgeUsage([
            ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.405510000'],
            ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.060000000'],
        ]);

        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.matches', false)
            ->where('days.1.matches', true)
            ->where('totals.reconciliation', 'mismatch')
            ->where('totals.mismatched_days', 1));
    });

    it('still reports our own numbers when Pixelforge cannot be reached', function () {
        reportFixtures();
        Http::fake(['pixelforge.test/v1/usage*' => fn () => throw new ConnectionException('down')]);

        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totals.reconciliation', 'unavailable')
            ->where('days.0.matches', null)
            ->where('totals.charged', '$0.5693875'));
    });

    it('lists days with no activity as zeros', function () {
        fakePixelforgeUsage([]);

        $this->actingAs(admin())->get(route('admin.report', ['from' => '2026-11-01', 'to' => '2026-11-03']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 3)
                ->where('days.1.generations', 0)
                ->where('days.1.charged', '$0.0000'));
    });
});

describe('by user and by day and user', function () {
    it('totals each user\'s charges, cost and profit', function () {
        reportFixtures();
        fakePixelforgeUsage([]);

        $this->actingAs(admin())->get(route('admin.report', [...REPORT_DATES, 'view' => 'user']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users', 2)
                ->where('users.0.email', 'ann@example.com')
                ->where('users.0.generations', 2)
                ->where('users.0.charged', '$0.4443875')
                ->where('users.0.provider_cost', '$0.35551')
                ->where('users.0.profit', '$0.0888775')
                ->where('users.1.email', 'bob@example.com')
                ->where('users.1.charged', '$0.1250'));
    });

    it('breaks every day down by user', function () {
        reportFixtures();
        fakePixelforgeUsage([]);

        $this->actingAs(admin())->get(route('admin.report', [...REPORT_DATES, 'view' => 'day_user']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('dayUsers', 3)
                ->where('dayUsers.0.day', '2026-11-17')
                ->where('dayUsers.0.email', 'ann@example.com')
                ->where('dayUsers.1.day', '2026-11-16')
                ->where('dayUsers.1.email', 'ann@example.com')
                ->where('dayUsers.2.email', 'bob@example.com'));
    });
});

describe('dates', function () {
    it('defaults to the last 30 days', function () {
        fakePixelforgeUsage([]);

        $this->actingAs(admin())->get(route('admin.report'))->assertInertia(fn (AssertableInertia $page) => $page->has('days', 30));
    });

    it('refuses a range longer than Pixelforge reports on', function () {
        fakePixelforgeUsage([]);

        $this->actingAs(admin())->get(route('admin.report', ['from' => '2026-01-01', 'to' => '2026-06-01']))
            ->assertSessionHasErrors('to');
    });

    it('refuses nonsense dates and an end before the start', function () {
        $this->actingAs(admin())->get(route('admin.report', ['from' => 'yesterday']))->assertSessionHasErrors('from');
        $this->actingAs(admin())->get(route('admin.report', ['from' => '2026-11-17', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
    });
});

describe('the admin account', function () {
    it('is made from the environment, never from the sign-up form', function () {
        config(['pixelforge.admin.email' => 'ops@example.com', 'pixelforge.admin.password' => 'a-long-random-password-1']);

        $this->artisan('pixelforge:admin')->assertSuccessful();

        $ops = User::where('email', 'ops@example.com')->firstOrFail();
        expect($ops->is_admin)->toBeTrue()
            ->and($ops->email_verified_at)->not->toBeNull()
            ->and(Hash::check('a-long-random-password-1', $ops->password))->toBeTrue();

        $this->artisan('pixelforge:admin')->assertSuccessful();
        expect(User::where('email', 'ops@example.com')->count())->toBe(1);
    });

    it('refuses to run without a password', function () {
        config(['pixelforge.admin.email' => 'ops@example.com', 'pixelforge.admin.password' => null]);

        $this->artisan('pixelforge:admin')->assertFailed();
    });
});

describe('the connection card', function () {
    it('gives the operator the webhook address to paste into Pixelforge, and never the secrets', function () {
        config(['pixelforge.webhook_secret' => 'whsec_super_secret_value']);
        fakePixelforgeUsage([]);

        $response = $this->actingAs(admin())->get(route('admin.report', REPORT_DATES));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connection.webhook_url', route('webhooks.pixelforge'))
            ->where('connection.api_key_configured', true)
            ->where('connection.webhook_secret_configured', true)
            ->where('connection.api_reachable', true)
            ->where('connection.last_event_at', null));
        expect($response->getContent())->not->toContain('pf_test_key')->not->toContain('whsec_super_secret_value');
    });

    it('says when something is missing or unreachable, and when the last webhook arrived', function () {
        config(['pixelforge.api_key' => null, 'pixelforge.webhook_secret' => null]);
        Http::fake(['pixelforge.test/v1/usage*' => Http::response('nope', 401)]);
        $event = PixelforgeEvent::create(['event_id' => 'evt_1', 'type' => 'ping', 'payload' => []]);

        $this->actingAs(admin())->get(route('admin.report', REPORT_DATES))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connection.api_key_configured', false)
            ->where('connection.webhook_secret_configured', false)
            ->where('connection.api_reachable', false)
            ->where('connection.last_event_at', $event->created_at->toIso8601String()));
    });
});
