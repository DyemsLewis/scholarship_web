@extends('layouts.app')

@section('title', 'Provider Service Workspace')
@section('page', $workspacePage)
@section('appAttributes') data-purchase-id="{{ $purchase->id }}" @endsection
