@extends('layouts.app')

@section('title', 'Message Provider')
@section('page', 'dashboardApplicationMessages')
@section('appAttributes')
    data-conversation-url="{{ $conversationUrl }}"
    data-back-url="{{ $backUrl }}"
    data-back-label="{{ $backLabel }}"
@endsection
