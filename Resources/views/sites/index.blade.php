@extends('layouts.app')

@section('title', __('Website Chat'))

@section('content')
<div class="section-heading">{{ __('Website Chat') }}</div>

@include('partials/flash_messages')
@include('wordpresschat::schema_notice')

<div class="row-container form-container">
    <div class="row">
        <div class="col-xs-12">
            <p class="margin-top">
                <a href="{{ route('wordpresschat.sites.create') }}" class="btn btn-primary">{{ __('Add Website') }}</a>
            </p>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Website') }}</th>
                            <th>{{ __('Mailbox') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (!count($sites))
                            <tr>
                                <td colspan="4" class="text-help">{{ __('No websites connected yet.') }}</td>
                            </tr>
                        @endif
                        @foreach ($sites as $site)
                        <tr>
                            <td>{{ $site->name }}</td>
                            <td>{{ $site->origin }}</td>
                            <td>{{ $site->mailbox ? $site->mailbox->name : '—' }}</td>
                            <td class="text-right">
                                <a href="{{ route('wordpresschat.sites.edit', ['id' => $site->id]) }}" class="btn btn-default btn-xs">{{ __('Edit') }}</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
