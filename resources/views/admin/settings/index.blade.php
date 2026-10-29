@extends('layouts.admin')

@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
    <div class="mb-6">
        <h2 class="text-base font-semibold text-gray-900">Platform Settings</h2>
        <p class="mt-1 text-sm text-gray-600">Operational defaults and integration readiness for the automated review platform.</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="font-semibold text-gray-900">Application</h3>
            </div>
            <dl class="divide-y divide-gray-100">
                @foreach ($settings['application'] as $label => $value)
                    <div class="grid gap-3 px-6 py-4 sm:grid-cols-3">
                        <dt class="text-sm font-medium text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="font-semibold text-gray-900">Queue And Worker</h3>
            </div>
            <dl class="divide-y divide-gray-100">
                @foreach ($settings['queue'] as $label => $value)
                    <div class="grid gap-3 px-6 py-4 sm:grid-cols-3">
                        <dt class="text-sm font-medium text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="font-semibold text-gray-900">Security Controls</h3>
            </div>
            <dl class="divide-y divide-gray-100">
                @foreach ($settings['security'] as $label => $value)
                    <div class="grid gap-3 px-6 py-4 sm:grid-cols-3">
                        <dt class="text-sm font-medium text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</dt>
                        <dd class="text-sm text-gray-900 sm:col-span-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="font-semibold text-gray-900">Integration Health</h3>
            </div>
            <div class="grid grid-cols-2 gap-4 p-6">
                @foreach ($settings['integrations'] as $label => $value)
                    <div class="rounded-md border border-gray-200 p-4">
                        <div class="text-2xl font-semibold text-gray-900">{{ $value }}</div>
                        <div class="mt-1 text-sm text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg bg-white shadow xl:col-span-2">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="font-semibold text-gray-900">Review Defaults</h3>
            </div>
            <div class="grid gap-4 p-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($settings['review_defaults'] as $label => $value)
                    <div class="rounded-md border border-gray-200 p-4">
                        <div class="text-sm font-medium text-gray-900">{{ str($label)->replace('_', ' ')->title() }}</div>
                        <div class="mt-2 text-sm text-gray-600">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endsection
