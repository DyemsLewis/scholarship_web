@extends('layouts.app')

@section('title', 'Selection Activity')
@section('page', 'providerSelectionActivityRecord')
@section('appAttributes')
    data-application-id="{{ $application->id }}"
@endsection
