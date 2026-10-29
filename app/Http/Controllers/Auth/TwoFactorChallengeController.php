<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticationService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        protected TwoFactorAuthenticationService $twoFactor,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $userId = $request->session()->get('login.id');
        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::query()->find($userId);
        if (! $user || ! $this->twoFactor->verifyUser($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'The provided authentication code was invalid.']);
        }

        Auth::login($user, (bool) $request->session()->pull('login.remember', false));
        $request->session()->forget('login.id');
        $request->session()->regenerate();
        $request->session()->put('auth.two_factor_confirmed_at', now()->timestamp);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
