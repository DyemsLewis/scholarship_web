@extends('layouts.app')

@section('title', 'Recipient Monitoring')
@section('page', 'providerProgramMonitoring')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
