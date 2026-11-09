@extends('layouts.app')

@section('title', 'Scholarship Support Record')
@section('page', 'dashboardMonitoringDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
    data-monitoring-section="{{ $section ?? 'overview' }}"
    data-monitoring-record="{{ $record ?? '' }}"
@endsection
