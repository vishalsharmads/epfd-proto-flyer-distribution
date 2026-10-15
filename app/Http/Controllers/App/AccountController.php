<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\ContentPage;
use App\Models\SavedArea;
use App\Models\SavedCard;
use App\Rules\Abn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public const TABS = ['profile', 'payments', 'invoices', 'security', 'legal', 'areas'];

    public function show(Request $request): Response
    {
        $user = $request->user();
        $b = $user->business;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'profile';

        return Inertia::render('app/account/index', [
            'tab' => $tab,
            'industries' => RegisterRequest::INDUSTRIES,
            'profile' => [
                'business_name' => $b->name, 'contact_name' => $b->contact_name, 'email' => $user->email, 'mobile' => $user->mobile ?? $b->mobile,
                'address' => $b->address, 'abn' => $b->abn, 'industry' => $b->industry, 'status' => $b->status,
            ],
            'cards' => $b->savedCards()->orderByDesc('is_default')->latest()->get()->map(fn (SavedCard $c) => $c->only('id', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name', 'is_default')),
            'credits_cents' => $b->creditBalanceCents(),
            'invoices' => $b->invoices()->with('invoiceable')->latest('issued_at')->limit(100)->get()->map(fn ($i) => [
                ...Presenter::invoice($i),
                'reference' => $i->invoiceable?->reference,
            ]),
            'legal' => ContentPage::where('type', 'legal')->where('is_published', true)->orderBy('sort')->get(['slug', 'title', 'updated_at']),
            'areas' => SavedArea::where('business_id', $b->id)->withGeoJson()->latest()->get()->map(fn (SavedArea $a) => [
                'id' => $a->id, 'name' => $a->name, 'houses' => $a->houses, 'units' => $a->units, 'geometry' => $a->geoJson(), 'created_at' => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $request->merge(['abn' => Abn::normalise($request->input('abn')), 'mobile' => preg_replace('/[^\d+]/', '', (string) $request->input('mobile'))]);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'regex:/^(?:04\d{8}|\+614\d{8})$/'],
            'address' => ['required', 'string', 'max:255'],
            'abn' => ['nullable', new Abn],
            'industry' => ['required', 'in:'.implode(',', RegisterRequest::INDUSTRIES)],
        ], ['mobile.regex' => 'Enter an Australian mobile number, e.g. 0412 345 678.']);
        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            $user->business->update(['name' => $data['business_name'], 'contact_name' => $data['contact_name'], 'mobile' => $data['mobile'], 'address' => $data['address'], 'abn' => $data['abn'], 'industry' => $data['industry']]);
            $user->update(['name' => $data['contact_name'], 'mobile' => $data['mobile']]);
        });

        return back()->with('success', 'Profile saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers(), 'different:current_password'],
        ], ['current_password.current_password' => 'Your current password is incorrect.', 'password.different' => 'Choose a password you haven\'t used here.']);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();

        return back()->with('success', 'Password changed.');
    }

    public function deleteCard(Request $request, SavedCard $card): RedirectResponse
    {
        abort_unless($card->business_id === $request->user()->business_id, 403);
        $wasDefault = $card->is_default;
        $card->delete();
        if ($wasDefault) {
            SavedCard::where('business_id', $card->business_id)->latest()->first()?->update(['is_default' => true]);
        }

        return back()->with('success', 'Card removed.');
    }

    public function defaultCard(Request $request, SavedCard $card): RedirectResponse
    {
        abort_unless($card->business_id === $request->user()->business_id, 403);
        DB::transaction(function () use ($card) {
            SavedCard::where('business_id', $card->business_id)->update(['is_default' => false]);
            $card->update(['is_default' => true]);
        });

        return back()->with('success', "Card ending {$card->last4} is now your default.");
    }

    public function deleteArea(Request $request, SavedArea $area): RedirectResponse
    {
        abort_unless($area->business_id === $request->user()->business_id, 403);
        $area->delete();

        return back()->with('success', 'Saved area deleted.');
    }
}
