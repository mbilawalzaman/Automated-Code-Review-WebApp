@extends('layouts.admin')

@section('title', 'Edit Repository')
@section('page-title', 'Edit Repository')

@section('content')
    <div class="max-w-4xl rounded-lg bg-white p-6 shadow">
        <form method="POST" action="{{ route('admin.repositories.update', $repository) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.repositories.partials.form', [
                'repository' => $repository,
                'projects' => $projects,
                'statuses' => $statuses,
                'submitLabel' => 'Update Repository',
            ])
        </form>
    </div>
@endsection
