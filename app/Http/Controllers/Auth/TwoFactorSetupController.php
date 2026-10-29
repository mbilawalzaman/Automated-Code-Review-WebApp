<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorSetupController extends Controller
{
    public function __construct(
        protected TwoFactorAuthenticationService $twoFactor,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $enabled = $this->twoFactor->isEnabled($user);
        $secret = $request->session()->get('two_factor.setup_secret');
        $qrSvg = null;

        if (! $enabled && ! $secret) {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put('two_factor.setup_secret', $secret);
        }

        if (! $enabled && $secret) {
            $qrSvg = $this->twoFactor->qrCodeSvg($user, $secret);
        }

        return view('auth.two-factor-setup', [
            'enabled' => $enabled,
            'secret' => $secret,
            'qrSvg' => $qrSvg,
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes', []),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $secret = $request->session()->get('two_factor.setup_secret');
        if (! $secret) {
            return back()->withErrors(['code' => 'Setup session expired. Reload and try again.']);
        }

        try {
            $codes = $this->twoFactor->enable($request->user(), $secret, $request->string('code')->toString());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $request->session()->forget('two_factor.setup_secret');
        $request->session()->put('two_factor.recovery_codes', $codes);
        $request->session()->put('auth.two_factor_confirmed_at', now()->timestamp);

        return redirect()->route('two-factor.show')->with('status', 'Two-factor authentication enabled.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        if (! $this->twoFactor->verifyUser($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        $this->twoFactor->disable($user);
        $request->session()->forget(['two_factor.recovery_codes', 'auth.two_factor_confirmed_at']);

        return redirect()->route('two-factor.show')->with('status', 'Two-factor authentication disabled.');
    }
}
