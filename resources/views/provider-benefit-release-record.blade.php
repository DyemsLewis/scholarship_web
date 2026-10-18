@extends('layouts.app')

@section('title', 'Benefit Release Record')
@section('page', 'providerBenefitReleaseRecord')
@section('appAttributes')
    data-release-id="{{ $release->id }}"
@endsection
