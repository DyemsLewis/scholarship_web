@extends('layouts.app')

@section('title', 'Application Messages')
@section('page', 'providerApplicationMessageInbox')
@section('appAttributes')
    data-inbox-url="{{ $inboxUrl }}"
    data-inbox-data-url="{{ $dataUrl }}"
    data-inbox-filter="{{ $inboxContext['filter'] }}"
    data-inbox-page="{{ $inboxContext['page'] }}"
@endsection
