@extends('layouts.app')

@section('title', 'Provider Applicant Review')
@section('page', 'providerApplicationDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
