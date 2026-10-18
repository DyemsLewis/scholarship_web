@extends('layouts.app')

@section('title', isset($session) ? 'Edit Selection Session' : 'Create Selection Session')
@section('page', 'providerSelectionSessionForm')
@section('appAttributes')
    @isset($session)
        data-session-id="{{ $session->id }}"
    @endisset
@endsection
