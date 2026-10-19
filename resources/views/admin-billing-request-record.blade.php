@extends('layouts.app')

@section('title', 'Service Request')
@section('page', $workspacePage)
@section('appAttributes') data-purchase-id="{{ $purchase->id }}" @endsection
