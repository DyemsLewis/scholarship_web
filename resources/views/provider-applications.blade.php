@extends('layouts.app')

@section('title', 'Provider Applications')
@section('page', 'providerApplications')
@section('appAttributes')
    @isset($scholarship)
        data-scholarship-id="{{ $scholarship->id }}"
        data-scholarship-title="{{ $scholarship->title }}"
    @endisset
@endsection
