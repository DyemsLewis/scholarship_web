@extends('layouts.app')

@section('title', 'Provider Program Form')
@section('page', 'providerProgramForm')

@section('appAttributes') data-scoring-presets="{{ json_encode($scoringPresets) }}" @endsection
