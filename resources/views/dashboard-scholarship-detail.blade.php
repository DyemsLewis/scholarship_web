@extends('layouts.app')

@section('title', 'Scholarship Details')
@section('page', 'dashboardScholarshipDetail')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
