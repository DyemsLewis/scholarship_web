@extends('layouts.app')

@section('title', 'Program Workspace')
@section('page', 'providerProgramWorkspace')
@section('appAttributes')
    data-scholarship-id="{{ $scholarship->id }}"
@endsection
