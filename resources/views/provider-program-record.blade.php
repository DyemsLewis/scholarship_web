@extends('layouts.app')

@section('title', $scholarship->title)
@section('page', 'providerProgramRecord')
@section('appAttributes') data-program-id="{{ $scholarship->id }}" @endsection
