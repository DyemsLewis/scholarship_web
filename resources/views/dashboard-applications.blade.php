@extends('layouts.app')

@section('title', 'Applicant Applications')
@section('page', 'dashboardApplications')
@isset($scholarship)
    @section('appAttributes')
        data-scholarship-id="{{ $scholarship->id }}"
    @endsection
@endisset
