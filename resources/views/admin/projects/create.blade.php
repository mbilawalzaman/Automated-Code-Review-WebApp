@extends('layouts.admin')

@section('title', 'Create Project')
@section('page-title', 'Create Project')

@section('content')
    <div class="max-w-3xl rounded-lg bg-white p-6 shadow">
        <form method="POST" action="{{ route('admin.projects.store') }}" class="space-y-6">
            @csrf

            @include('admin.projects.partials.form', [
                'project' => null,
                'submitLabel' => 'Create Project',
            ])
        </form>
    </div>
@endsection
