@extends('layouts.app')

@section('title', 'Program Verification Requirements')
@section('page', 'providerProgramAccess')
@section('appAttributes') data-program-access="{{ json_encode($programAccess) }}" @endsection
