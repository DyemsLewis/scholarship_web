@extends('layouts.app')

@section('title', 'Monitoring Plan')
@section('page', 'providerMonitoringPlan')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
