@extends('layouts.app')

@section('title', 'Payment Receipt')
@section('page', 'adminFinanceReceipt')
@section('appAttributes') data-purchase-id="{{ $purchase->id }}" @endsection
