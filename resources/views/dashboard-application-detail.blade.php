@extends('layouts.app')

@php
    $applicationPage = match ($section ?? 'summary') {
        'summary' => 'dashboardApplicationOverview',
        'submission' => 'dashboardApplicationSubmission',
        'activities' => 'dashboardApplicationActivities',
        'outcome' => 'dashboardApplicationOutcome',
        'history' => 'dashboardApplicationHistory',
        default => 'dashboardApplicationDetail',
    };
@endphp

@section('title', 'Application Details')
@section('page', $applicationPage)
@section('appAttributes')
    data-application-id="{{ $application->id }}"
    data-application-section="{{ $section ?? 'summary' }}"
@endsection
