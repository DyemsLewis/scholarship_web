@extends('layouts.app')

@section('title', 'Recipient Record')
@section('page', 'providerRecipientRecord')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
