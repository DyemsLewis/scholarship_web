@extends('layouts.app')

@section('title', 'Monitoring Check-in')
@section('page', 'providerMonitoringCheckInRecord')
@section('appAttributes')
    data-cycle-id="{{ $cycle->id }}"
@endsection
