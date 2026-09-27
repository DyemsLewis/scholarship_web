@extends('layouts.app')

@section('title', 'Recipient Monitoring')
@section('page', 'providerMonitoringWorkspace')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
