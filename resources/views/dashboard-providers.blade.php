@extends('layouts.app')

@section('title', isset($provider) ? 'Scholarship Provider' : 'Scholarship Providers')
@section('page', 'dashboardProviders')
@if (isset($provider))
    @section('appAttributes')
        data-provider-id="{{ $provider->id }}"
    @endsection
@endif
