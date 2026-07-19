@extends('layouts.app')

@section('title', 'Admin Provider Review')
@section('page', 'adminProviderReview')
@section('appAttributes')
    data-provider-id="{{ $provider->id }}"
@endsection
