@extends('layouts.app')

@section('title', $title)
@section('page', 'adminPortalManagerArea')
@section('appAttributes') data-area="{{ $area }}" @endsection
