@extends('layouts.app')

@section('title', 'Activity Record')
@section('page', 'adminRecordsActivityRecord')
@section('appAttributes') data-entry-id="{{ $entry->id }}" @endsection
