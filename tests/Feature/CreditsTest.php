<?php

use App\Credits\CreditLedger;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Generations\GenerationSettler;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pf_test_key', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgePricing())]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function creditsInput(array $overrides = []): array
{
    return ['request_id' => (string) Str::uuid(), 'prompt' => str_repeat('p', 40), 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], ...$overrides];
}

describe('the ledger', function () {
    it('works out balance, what is held for running images, and what is available', function () {
        $user = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $ledger->add($user, CreditTransactionType::TopUp, 5_000_000_000);
        $ledger->add($user, CreditTransactionType::Charge, -1_250_000_000);
        Generation::factory()->for($user)->processing()->create(['estimated_max_nano' => 400_000_000]);
        Generation::factory()->for($user)->create(['estimated_max_nano' => 100_000_000]);
        Generation::factory()->for($user)->accepted()->create(['status' => GenerationStatus::Failed, 'estimated_max_nano' => 999_000_000]);

        expect($ledger->balanceNano($user))->toBe(3_750_000_000)
            ->and($ledger->reservedNano($user))->toBe(500_000_000)
            ->and($ledger->availableNano($user))->toBe(3_250_000_000);
    });

    it('keeps one user\'s credits apart from another\'s', function () {
        $ledger = app(CreditLedger::class);
        $ledger->add(User::factory()->create(), CreditTransactionType::TopUp, 9_000_000_000);

        expect($ledger->balanceNano(User::factory()->create()))->toBe(0);
    });
});

describe('welcome credit', function () {
    it('gives a new account its welcome credit and nothing else', function () {
        $this->post(route('register.store'), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        expect(app(CreditLedger::class)->balanceNano($user))->toBe(1_000_000_000)
            ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::SignupBonus);
    });
});

describe('charging', function () {
    it('takes exactly what the image cost, once, however many times it is told', function () {
        $user = fundedUser(5);
        $generation = Generation::factory()->for($user)->processing()->create();
        $payload = pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id);

        $settler = app(GenerationSettler::class);
        $settler->apply($payload);
        $settler->apply($payload);
        $settler->apply([...$payload, 'status' => 'processing']);

        $charge = CreditTransaction::where('type', CreditTransactionType::Charge)->sole();
        expect($charge->amount_nano)->toBe(-381_887_500)
            ->and($charge->generation_id)->toBe($generation->id)
            ->and(app(CreditLedger::class)->balanceNano($user))->toBe(5_000_000_000 - 381_887_500);
    });

    it('charges nothing when an image fails', function () {
        $user = fundedUser(5);
        $generation = Generation::factory()->for($user)->processing()->create();

        app(GenerationSettler::class)->apply(pixelforgePayload($generation->pixelforge_id, 'failed', $generation->id));

        expect(CreditTransaction::where('type', CreditTransactionType::Charge)->count())->toBe(0)
            ->and(app(CreditLedger::class)->balanceNano($user))->toBe(5_000_000_000);
    });

    it('is protected by the database too: one charge per generation, ever', function () {
        $generation = Generation::factory()->processing()->create(['charged_nano' => 100, 'status' => GenerationStatus::Succeeded]);
        $ledger = app(CreditLedger::class);
        $ledger->charge($generation);

        $ledger->charge($generation);
    })->throws(QueryException::class);
});

describe('blocking images that cannot be afforded', function () {
    it('refuses an image whose highest price is more than the user has', function () {
        Queue::fake();
        $user = User::factory()->create();
        app(CreditLedger::class)->add($user, CreditTransactionType::TopUp, 50_000_000);

        $this->actingAs($user)
            ->post(route('generations.store'), creditsInput())
            ->assertSessionHasErrors('form');

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('accepts an image whose highest price fits', function () {
        Queue::fake();
        $user = User::factory()->create();
        app(CreditLedger::class)->add($user, CreditTransactionType::TopUp, 90_000_000);

        $this->actingAs($user)
            ->post(route('generations.store'), creditsInput())
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SubmitGeneration::class);
    });

    it('holds credits for images still running, so they cannot be spent twice', function () {
        Queue::fake();
        $user = User::factory()->create();
        app(CreditLedger::class)->add($user, CreditTransactionType::TopUp, 200_000_000);
        Generation::factory()->for($user)->processing()->create(['estimated_max_nano' => 150_000_000]);

        $this->actingAs($user)
            ->post(route('generations.store'), creditsInput())
            ->assertSessionHasErrors('form');

        expect(Generation::count())->toBe(1);
    });

    it('says whether the current form is affordable while the user is still typing', function () {
        $poor = User::factory()->create();
        $rich = fundedUser(5);

        $this->actingAs($poor)->postJson(route('generations.estimate'), creditsInput())
            ->assertOk()->assertJson(['affordable' => false, 'available' => '$0.00']);
        $this->actingAs($rich)->postJson(route('generations.estimate'), creditsInput())
            ->assertOk()->assertJson(['affordable' => true, 'available' => '$5.00']);
    });
});

describe('buying credits', function () {
    it('shows the balance, the packs and only the user\'s own transactions', function () {
        $user = fundedUser(5);
        CreditTransaction::factory()->create(['description' => 'Somebody else']);

        $this->actingAs($user)
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('credits/Index')
                ->where('balance', '$5.00')
                ->where('available', '$5.00')
                ->has('packs', 3)
                ->has('transactions.data', 1));
    });

    it('adds credits straight away, with no payment', function () {
        $user = fundedUser(1);

        $this->actingAs($user)->post(route('credits.store'), ['request_id' => (string) Str::uuid(), 'pack' => 10])->assertRedirect();

        expect(app(CreditLedger::class)->balanceNano($user))->toBe(11_000_000_000);
    });

    it('only sells the packs on offer', function (mixed $pack) {
        $user = fundedUser(1);

        $this->actingAs($user)->post(route('credits.store'), ['request_id' => (string) Str::uuid(), 'pack' => $pack])->assertSessionHasErrors('pack');

        expect(app(CreditLedger::class)->balanceNano($user))->toBe(1_000_000_000);
    })->with([7, 0, -5, 1000000, 'lots', null]);

    it('sends guests to log in', function () {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
        $this->post(route('credits.store'), ['request_id' => (string) Str::uuid(), 'pack' => 5])->assertRedirect(route('login'));
    });
});

describe('edge cases', function () {
    it('adds a top-up once when the same click is sent twice', function () {
        $user = fundedUser(1);
        $id = (string) Str::uuid();

        $this->actingAs($user)->post(route('credits.store'), ['request_id' => $id, 'pack' => 5]);
        $this->actingAs($user)->post(route('credits.store'), ['request_id' => $id, 'pack' => 5]);

        expect(app(CreditLedger::class)->balanceNano($user))->toBe(6_000_000_000);
    });

    it('adds two top-ups for two separate clicks', function () {
        $user = fundedUser(1);

        $this->actingAs($user)->post(route('credits.store'), ['request_id' => (string) Str::uuid(), 'pack' => 5]);
        $this->actingAs($user)->post(route('credits.store'), ['request_id' => (string) Str::uuid(), 'pack' => 5]);

        expect(app(CreditLedger::class)->balanceNano($user))->toBe(11_000_000_000);
    });

    it('gives the held credits straight back when an image fails', function () {
        $user = fundedUser(1);
        $generation = Generation::factory()->for($user)->processing()->create(['estimated_max_nano' => 400_000_000]);
        $ledger = app(CreditLedger::class);
        expect($ledger->availableNano($user))->toBe(600_000_000);

        app(GenerationSettler::class)->apply(pixelforgePayload($generation->pixelforge_id, 'failed', $generation->id));

        expect($ledger->availableNano($user))->toBe(1_000_000_000)
            ->and($ledger->balanceNano($user))->toBe(1_000_000_000);
    });

    it('lets a balance dip below zero when prices rose mid-flight, and then blocks new images', function () {
        Queue::fake();
        $user = User::factory()->create();
        app(CreditLedger::class)->add($user, CreditTransactionType::TopUp, 300_000_000);
        $generation = Generation::factory()->for($user)->processing()->create(['estimated_max_nano' => 300_000_000]);

        app(GenerationSettler::class)->apply(pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id));

        expect(app(CreditLedger::class)->balanceNano($user))->toBe(300_000_000 - 381_887_500);
        $this->actingAs($user)->post(route('generations.store'), creditsInput())->assertSessionHasErrors('form');
        Queue::assertNothingPushed();
    });

    it('keeps the books when an account is deleted', function () {
        $user = fundedUser(5);
        $generation = Generation::factory()->for($user)->processing()->create();
        app(GenerationSettler::class)->apply(pixelforgePayload($generation->pixelforge_id, 'succeeded', $generation->id));

        $user->delete();

        expect(Generation::count())->toBe(1)
            ->and($generation->refresh()->user_id)->toBeNull()
            ->and($generation->charged_nano)->toBe(381_887_500)
            ->and(CreditTransaction::where('type', CreditTransactionType::Charge)->sole()->user_id)->toBeNull();
    });
});
