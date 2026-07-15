@extends('layouts.app')

@section('title', 'Provider Application Details')
@section('page', 'providerApplicationDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
