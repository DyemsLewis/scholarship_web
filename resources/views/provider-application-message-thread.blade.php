@extends('layouts.app')

@section('title', 'Application Conversation')
@section('page', 'providerApplicationMessageThread')
@section('appAttributes')
    data-conversation-url="{{ $conversationUrl }}"
    data-conversation-side="provider"
    data-back-url="{{ $backUrl }}"
    data-back-label="messages"
@endsection
