@extends('layouts.admin')

@section('title', 'Add Repository')
@section('page-title', 'Add Repository')

@section('content')
    <div class="max-w-4xl rounded-lg bg-white p-6 shadow">
        <form method="POST" action="{{ route('admin.repositories.store') }}" class="space-y-6">
            @csrf

            @include('admin.repositories.partials.form', [
                'repository' => null,
                'projects' => $projects,
                'statuses' => $statuses,
                'submitLabel' => 'Add Repository',
            ])
        </form>
    </div>
@endsection
