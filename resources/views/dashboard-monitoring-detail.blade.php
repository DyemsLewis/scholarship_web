@extends('layouts.app')

@section('title', 'Monitoring Record')
@section('page', 'dashboardMonitoringDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
