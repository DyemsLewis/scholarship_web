@extends('layouts.app')

@section('title', 'Admin Applicant Review')
@section('page', 'adminApplicantReview')
@section('appAttributes')
    data-applicant-id="{{ $applicant->id }}"
@endsection
