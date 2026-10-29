@extends('layouts.admin')

@section('title', 'Edit User')
@section('page-title', 'Edit User')

@section('content')
<div class="max-w-3xl space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Edit System User</h2>
            <p class="text-sm text-gray-500">Update account details, role assignments, or reset password.</p>
        </div>
        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            &larr; Back to Users
        </a>
    </div>

    <div class="bg-white shadow rounded-lg p-6 border border-gray-200">
        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
                @error('name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
                @error('email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6"
                 x-data="{
                     showPass: false,
                     showConfirmPass: false,
                     password: '',
                     passwordConfirmation: '',
                     get isMismatch() {
                         return this.passwordConfirmation.length > 0 && this.password !== this.passwordConfirmation;
                     },
                     validateMatch() {
                         const input = this.$refs.confirmInput;
                         if (!input) return;
                         if (this.isMismatch) {
                             input.setCustomValidity('The password confirmation does not match.');
                         } else {
                             input.setCustomValidity('');
                         }
                     }
                 }"
                 x-init="$watch('password', () => validateMatch()); $watch('passwordConfirmation', () => validateMatch());">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">New Password (optional)</label>
                    <div class="relative mt-1">
                        <input
                            :type="showPass ? 'text' : 'password'"
                            x-model="password"
                            name="password"
                            id="password"
                            class="block w-full rounded-md border-gray-300 pr-10 shadow-sm focus:border-gray-900 focus:ring-gray-900 sm:text-sm"
                            placeholder="Leave blank to keep current"
                        >
                        <button
                            type="button"
                            x-on:click="showPass = !showPass"
                            class="absolute inset-y-0 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                            style="right: 12px;"
                            aria-label="Toggle password visibility"
                        >
                            <svg x-show="!showPass" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg x-show="showPass" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                    <div class="relative mt-1">
                        <input
                            :type="showConfirmPass ? 'text' : 'password'"
                            x-ref="confirmInput"
                            x-model="passwordConfirmation"
                            name="password_confirmation"
                            id="password_confirmation"
                            class="block w-full rounded-md pr-10 shadow-sm sm:text-sm transition-colors"
                            :class="isMismatch ? 'border-red-300 text-red-900 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-gray-900 focus:ring-gray-900'"
                        >
                        <button
                            type="button"
                            x-on:click="showConfirmPass = !showConfirmPass"
                            class="absolute inset-y-0 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                            style="right: 12px;"
                            aria-label="Toggle password confirmation visibility"
                        >
                            <svg x-show="!showConfirmPass" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg x-show="showConfirmPass" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                    <p x-show="isMismatch" x-cloak class="mt-1 text-xs text-red-600">
                        The password confirmation does not match.
                    </p>
                    @error('password_confirmation')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                <select name="role" id="role" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" {{ auth()->id() === $user->id ? 'disabled' : '' }}>
                    @foreach ($roles as $roleOption)
                        <option value="{{ $roleOption->value }}" {{ old('role', $user->role?->value) === $roleOption->value ? 'selected' : '' }}>
                            {{ $roleOption->label() }}
                        </option>
                    @endforeach
                </select>
                @if(auth()->id() === $user->id)
                    <input type="hidden" name="role" value="{{ $user->role?->value }}">
                    <p class="mt-1 text-xs text-gray-500">You cannot demote your own active account role.</p>
                @endif
                @error('role')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                <a href="{{ route('admin.users.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition">
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
