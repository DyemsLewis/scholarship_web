@extends('layouts.app')

@section('title', 'Selection Session')
@section('page', 'providerSelectionSessionRecord')
@section('appAttributes')
    data-session-id="{{ $session->id }}"
    data-program-id="{{ $session->scholarship_id }}"
@endsection
