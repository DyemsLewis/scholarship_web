@extends('layouts.app')

@section('title', isset($provider) ? 'Scholarship Provider' : 'Scholarship Providers')
@section('page', 'dashboardProviders')
@if (!empty($preview))
    @section('head')
        <meta name="robots" content="noindex,nofollow,noarchive">
    @endsection
@endif
@if (isset($provider))
    @section('appAttributes')
        data-provider-id="{{ $provider->id }}"
        @if (!empty($preview))
            data-provider-preview="true"
            data-preview-data-url="{{ route('provider.previews.organization.data', absolute: false) }}"
            data-preview-back-url="{{ $previewBackUrl }}"
        @endif
    @endsection
@endif
