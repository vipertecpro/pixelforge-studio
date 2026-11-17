<?php

namespace App\Http\Controllers;

use App\Credits\CreditLedger;
use App\Enums\CreditTransactionType;
use App\Http\Resources\CreditTransactionResource;
use App\Pricing\Nano;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, CreditLedger $ledger): Response
    {
        $user = $request->user();

        return Inertia::render('credits/Index', [
            'balance' => Nano::format($ledger->balanceNano($user), 2),
            'reserved' => Nano::format($ledger->reservedNano($user), 2),
            'available' => Nano::format($ledger->availableNano($user), 2),
            'packs' => array_map(fn (int $usd): array => ['usd' => $usd, 'label' => '$'.$usd], $this->packs()),
            'transactions' => CreditTransactionResource::collection(
                $user->creditTransactions()->with('generation:id,prompt')->latest('id')->paginate(10)
            ),
        ]);
    }

    /**
     * A pretend purchase: it adds credits straight away and takes no payment.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'integer', Rule::in($this->packs())],
        ]);

        $ledger->add(
            $request->user(),
            CreditTransactionType::TopUp,
            $validated['pack'] * Nano::PER_DOLLAR,
            'Test top-up (no payment taken)',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => '$'.$validated['pack'].' of credits added.']);

        return back();
    }

    /**
     * @return list<int>
     */
    private function packs(): array
    {
        /** @var list<int> */
        return config('pixelforge.credits.packs_usd');
    }
}
