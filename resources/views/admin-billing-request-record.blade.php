@extends('layouts.app')

@section('title', 'Service Request')
@section('page', 'adminBillingRequestRecord')
@section('appAttributes') data-purchase-id="{{ $purchase->id }}" @endsection
