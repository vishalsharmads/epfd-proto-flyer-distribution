<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Business;
use App\Models\User;
use App\Rules\Abn;
use App\Services\AbnLookupService;
use App\Support\Audit;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('auth/register', ['industries' => RegisterRequest::INDUSTRIES]);
    }

    /** ABN lookup (Australian Business Register) for the inline "Registered to …" hint. */
    public function abn(Request $request, AbnLookupService $abr): JsonResponse
    {
        $abn = (string) $request->query('abn');
        if (! Abn::valid($abn)) {
            return response()->json(['message' => 'Enter a valid 11-digit ABN.'], 422);
        }
        $hit = $abr->lookup($abn);

        return $hit ? response()->json(['data' => $hit]) : response()->json(['message' => 'We could not find that ABN on the Australian Business Register.'], 404);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['business_name'],
                'contact_name' => $data['contact_name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'abn' => $data['abn'] ?? null,
                'industry' => $data['industry'],
                'address' => $data['address'],
                'address_gnaf_pid' => $data['address_gnaf_pid'] ?? null,
                'state' => $data['state'] ?? null,
                'geom' => isset($data['address_lng'], $data['address_lat']) ? Geo::point((float) $data['address_lng'], (float) $data['address_lat']) : null,
                'status' => Business::PENDING,
            ]);

            return User::create([
                'business_id' => $business->id,
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'password' => $data['password'],
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();
        Audit::log('business.registered', $user->business);
        OtpController::issue($user);

        return redirect()->route('verify.otp')->with('success', "We've emailed a 6-digit code to {$user->email}.");
    }
}
