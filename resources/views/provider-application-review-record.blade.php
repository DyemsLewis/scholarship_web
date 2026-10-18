@extends('layouts.app')

@section('title', 'Application Review')
@section('page', 'providerApplicationReviewRecord')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
