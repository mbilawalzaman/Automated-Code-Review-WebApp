@extends('layouts.admin')

@section('title', 'Two-Factor Authentication')
@section('page-title', 'Two-Factor Authentication')

@section('content')
    <div class="mx-auto max-w-2xl rounded-lg bg-white p-6 shadow">
        <h2 class="text-base font-semibold text-gray-900">Authenticator app (TOTP)</h2>
        <p class="mt-1 text-sm text-gray-600">
            Required for remote dashboard access (SEC-008). VPN remains an infrastructure control outside this app.
        </p>

        @if ($enabled)
            {{-- Layout already shows session('status') after enable/disable; avoid a second green banner. --}}
            @unless (session('status'))
                <div class="mt-4 rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    Two-factor authentication is enabled for your account.
                </div>
            @endunless

            @if (! empty($recoveryCodes))
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-medium text-amber-900">Store these recovery codes now. They will not be shown again.</p>
                    <ul class="mt-2 grid grid-cols-2 gap-2 font-mono text-sm text-amber-950">
                        @foreach ($recoveryCodes as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-6 space-y-4">
                @csrf
                @method('DELETE')
                <div>
                    <x-input-label for="disable_code" value="Code to disable 2FA" />
                    <x-text-input id="disable_code" name="code" type="text" class="mt-1 block w-full" required />
                    <x-input-error class="mt-2" :messages="$errors->get('code')" />
                </div>
                <x-danger-button type="submit">Disable two-factor authentication</x-danger-button>
            </form>
        @else
            <div class="mt-4 space-y-4">
                @if ($qrSvg)
                    <div class="inline-block rounded border border-gray-200 bg-white p-3">
                        {!! $qrSvg !!}
                    </div>
                @endif
                <p class="text-sm text-gray-700">
                    Secret key:
                    <code class="rounded bg-gray-100 px-2 py-1 font-mono text-xs">{{ $secret }}</code>
                </p>

                <form method="POST" action="{{ route('two-factor.enable') }}" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="enable_code" value="Enter code from authenticator app" />
                        <x-text-input id="enable_code" name="code" type="text" class="mt-1 block w-full" required autocomplete="one-time-code" />
                        <x-input-error class="mt-2" :messages="$errors->get('code')" />
                    </div>
                    <x-primary-button type="submit">Enable two-factor authentication</x-primary-button>
                </form>
            </div>
        @endif
    </div>
@endsection
