<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationService
{
    public function __construct(
        protected Google2FA $google2fa = new Google2FA,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $company = (string) config('app.name', 'Code Review');
        $otpauth = $this->google2fa->getQRCodeUrl($company, (string) $user->email, $secret);

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd
        ));

        return $writer->writeString($otpauth);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return Collection::times($count, fn () => Str::lower(Str::random(4).'-'.Str::random(4)).'')
            ->values()
            ->all();
    }

    public function enable(User $user, string $secret, string $code): array
    {
        if (! $this->verify($secret, $code)) {
            throw new \InvalidArgumentException('Invalid authentication code.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return $this->google2fa->verifyKey($secret, $code);
    }

    public function verifyUser(User $user, string $code): bool
    {
        $secret = $this->plainSecret($user);
        if ($secret && $this->verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function plainSecret(User $user): ?string
    {
        if (! $user->two_factor_secret) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function plainRecoveryCodes(User $user): array
    {
        if (! $user->two_factor_recovery_codes) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);

            return is_array($decoded) ? array_values($decoded) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtolower(trim($code));
        $codes = $this->plainRecoveryCodes($user);
        $matchIndex = null;

        foreach ($codes as $index => $existing) {
            if (hash_equals(strtolower($existing), $normalized)) {
                $matchIndex = $index;
                break;
            }
        }

        if ($matchIndex === null) {
            return false;
        }

        unset($codes[$matchIndex]);
        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
        ])->save();

        return true;
    }

    public function isEnabled(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }
}
