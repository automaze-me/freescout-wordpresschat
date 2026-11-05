@extends('layouts.app')

@section('title', __('Website Chat'))

@section('content')
<div class="section-heading">{{ __('Website Chat') }}</div>

@include('partials/flash_messages')
@include('wordpresschat::schema_notice')
@endsection
