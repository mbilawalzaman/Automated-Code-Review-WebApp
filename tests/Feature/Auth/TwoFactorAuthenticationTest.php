<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    protected User $user;

    protected TwoFactorAuthenticationService $twoFactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
        ]);
        $this->twoFactor = app(TwoFactorAuthenticationService::class);
    }

    public function test_user_can_view_two_factor_setup_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('two-factor.show'))
            ->assertOk()
            ->assertSee('Authenticator app');
    }

    public function test_user_can_enable_two_factor_with_valid_code(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($this->user)
            ->withSession(['two_factor.setup_secret' => $secret])
            ->withConfirmedPassword()
            ->post(route('two-factor.enable'), ['code' => $code])
            ->assertRedirect(route('two-factor.show'));

        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_confirmed_at);
        $this->assertTrue($this->twoFactor->isEnabled($this->user));

        $this->actingAs($this->user)
            ->withSession(['status' => 'Two-factor authentication enabled.'])
            ->get(route('two-factor.show'))
            ->assertOk()
            ->assertSee('Two-factor authentication enabled.')
            ->assertDontSee('Two-factor authentication is enabled for your account.', false);
    }

    public function test_login_with_two_factor_enabled_redirects_to_challenge(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $this->user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(['abcd-efgh'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('login'), [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_valid_totp_completes_challenge(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $this->user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(['abcd-efgh'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->withSession(['login.id' => $this->user->id])
            ->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_recovery_code_completes_challenge_and_is_consumed(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $this->user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(['abcd-efgh', 'ijkl-mnop'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->withSession(['login.id' => $this->user->id])
            ->post(route('two-factor.challenge.store'), ['code' => 'abcd-efgh'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(['ijkl-mnop'], $this->twoFactor->plainRecoveryCodes($this->user->fresh()));
    }
}
