@extends('layouts.app')

@section('title', 'Final Decision')
@section('page', 'providerFinalDecisionRecord')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
