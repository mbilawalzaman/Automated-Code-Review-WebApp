<?php

namespace App\Http\Middleware\Auth;

use App\Services\Auth\TwoFactorAuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    public function __construct(
        protected TwoFactorAuthenticationService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->twoFactor->isEnabled($user) && ! $request->session()->get('auth.two_factor_confirmed_at')) {
            $userId = $user->id;
            Auth::logout();
            $request->session()->put('login.id', $userId);

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
