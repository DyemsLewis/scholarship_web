@extends('layouts.app')

@section('title', 'Admin Program Review')
@section('page', 'adminProgramReview')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
