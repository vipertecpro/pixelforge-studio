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
        $summary = $ledger->summary($user);

        return Inertia::render('credits/Index', [
            'balance' => Nano::format($summary['balance'], 2),
            'reserved' => Nano::format($summary['reserved'], 2),
            'available' => Nano::format(max(0, $summary['available']), 2),
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
            'request_id' => ['required', 'uuid'],
        ]);

        $ledger->add(
            $request->user(),
            CreditTransactionType::TopUp,
            $validated['pack'] * Nano::PER_DOLLAR,
            'Test top-up (no payment taken)',
            $validated['request_id'],
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
