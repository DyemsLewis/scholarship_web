@extends('layouts.app')

@section('title', 'Withdrawal Requests')
@section('page', 'providerRecipientWithdrawals')
@section('appAttributes')
    @if($withdrawal)
        data-withdrawal-id="{{ $withdrawal->id }}"
    @endif
@endsection
