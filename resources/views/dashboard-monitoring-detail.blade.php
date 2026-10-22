@extends('layouts.app')

@section('title', 'Monitoring Record')
@section('page', 'dashboardMonitoringDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
    data-monitoring-section="{{ $section ?? 'overview' }}"
    data-monitoring-record="{{ $record ?? '' }}"
@endsection
