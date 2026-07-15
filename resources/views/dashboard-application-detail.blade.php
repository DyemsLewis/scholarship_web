@extends('layouts.app')

@section('title', 'Application Details')
@section('page', 'dashboardApplicationDetail')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
