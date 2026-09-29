@extends('layouts.app')

@section('title', 'Admin Monitoring Review')
@section('page', 'adminMonitoringReview')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
