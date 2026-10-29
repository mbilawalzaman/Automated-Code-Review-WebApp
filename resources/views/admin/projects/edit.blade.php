@extends('layouts.admin')

@section('title', 'Edit Project')
@section('page-title', 'Edit Project')

@section('content')
    <div class="max-w-3xl rounded-lg bg-white p-6 shadow">
        <form method="POST" action="{{ route('admin.projects.update', $project) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.projects.partials.form', [
                'project' => $project,
                'submitLabel' => 'Update Project',
            ])
        </form>
    </div>
@endsection
