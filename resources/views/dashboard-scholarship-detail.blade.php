@extends('layouts.app')

@section('title', 'Scholarship Details')
@section('page', 'dashboardScholarshipDetail')
@if (!empty($preview))
    @section('head')
        <meta name="robots" content="noindex,nofollow,noarchive">
    @endsection
@endif
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
    data-scholarship-section="{{ $section ?? 'summary' }}"
    @if (!empty($preview))
        data-provider-preview="true"
        data-preview-data-url="{{ route('provider.previews.programs.data', $scholarship, false) }}"
        data-preview-back-url="{{ $previewBackUrl }}"
        data-preview-status="{{ Illuminate\Support\Str::headline($scholarship->status ?: 'draft') }}"
    @endif
@endsection
