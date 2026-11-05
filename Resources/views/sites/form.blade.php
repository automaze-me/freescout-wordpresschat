@extends('layouts.app')

@section('title', __('Website'))

@section('content')
<div class="section-heading">{{ $site->id ? __('Edit Website') : __('Add Website') }}</div>

@include('partials/flash_messages')

<div class="row-container form-container">
    <div class="row">
        <div class="col-xs-12">
            <form method="POST" action="{{ $site->id ? route('wordpresschat.sites.update', ['id' => $site->id]) : route('wordpresschat.sites.store') }}" class="form-horizontal margin-top">
                {{ csrf_field() }}

                <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Name') }}</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control input-sized" name="name" value="{{ old('name', $site->name) ?? '' }}" maxlength="100" required>
                        @include('partials/field_error', ['field' => 'name'])
                    </div>
                </div>

                <div class="form-group{{ $errors->has('url') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Website address') }}</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control input-sized" name="url" value="{{ old('url', $site->url) ?? '' }}" maxlength="255" placeholder="https://www.example.com" required>
                        @include('partials/field_error', ['field' => 'url'])
                        <p class="text-help">{{ __('Chat requests are only accepted from this website.') }}</p>
                    </div>
                </div>

                <div class="form-group{{ $errors->has('mailbox_id') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
                    <div class="col-sm-6">
                        <select name="mailbox_id" class="form-control input-sized" required>
                            @foreach ($mailboxes as $mailbox)
                                <option value="{{ $mailbox->id }}" @if ((int) old('mailbox_id', $site->mailbox_id) === $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                            @endforeach
                        </select>
                        @include('partials/field_error', ['field' => 'mailbox_id'])
                    </div>
                </div>

                <div class="form-group{{ $errors->has('fallback_delay') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Email fallback delay (seconds)') }}</label>
                    <div class="col-sm-6">
                        <input type="number" class="form-control input-sized" name="fallback_delay" value="{{ old('fallback_delay', $site->fallback_delay) }}" min="30" max="3600">
                        @include('partials/field_error', ['field' => 'fallback_delay'])
                        <p class="text-help">{{ __('How long to wait for the visitor to read a reply before it is also sent by email. 30 to 3600 seconds.') }} {{ __('Empty means 120 seconds.') }}</p>
                    </div>
                </div>

                <div class="form-group{{ $errors->has('max_attachment_mb') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Max attachment size (MB)') }}</label>
                    <div class="col-sm-6">
                        <input type="number" class="form-control input-sized" name="max_attachment_mb" value="{{ old('max_attachment_mb', $site->max_attachment_mb) }}" min="1" max="50">
                        @include('partials/field_error', ['field' => 'max_attachment_mb'])
                    </div>
                </div>

                <div class="form-group margin-top">
                    <div class="col-sm-6 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        <a href="{{ route('wordpresschat.sites.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    </div>
                </div>
            </form>

            @if ($site->id)
            <hr>
            <h4>{{ __('Connection details') }}</h4>
            <p class="text-help">{{ __('Enter these two values in the Live Chat for FreeScout plugin settings on your WordPress site.') }}</p>

            <div class="form-horizontal">
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Site ID') }}</label>
                    <div class="col-sm-6">
                        <div class="input-group">
                            <input type="text" class="form-control" id="wpc-site-id" value="{{ $site->public_id }}" readonly>
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default wpc-copy" data-target="wpc-site-id">{{ __('Copy') }}</button>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Secret') }}</label>
                    <div class="col-sm-6">
                        @if ($secret !== null)
                        <div class="input-group">
                            <input type="text" class="form-control" id="wpc-secret" value="{{ $secret }}" readonly>
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default wpc-copy" data-target="wpc-secret">{{ __('Copy') }}</button>
                            </span>
                        </div>
                        @else
                        <p class="form-control-static text-danger">{{ __("The secret can't be read (the FreeScout app key changed?). Regenerate it and update the WordPress plugin.") }}</p>
                        @endif
                    </div>
                </div>

                <div class="form-group">
                    <div class="col-sm-6 col-sm-offset-2">
                        <form method="POST" action="{{ route('wordpresschat.sites.secret', ['id' => $site->id]) }}" class="wpc-confirm" data-confirm="{{ __('Regenerate the secret? The WordPress plugin stops working until you enter the new secret there.') }}" style="display:inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-default">{{ __('Regenerate secret') }}</button>
                        </form>
                        <form method="POST" action="{{ route('wordpresschat.sites.destroy', ['id' => $site->id]) }}" class="wpc-confirm" data-confirm="{{ __('Delete this website? Its conversations stay in FreeScout, but the chat on the website stops working.') }}" style="display:inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-danger">{{ __('Delete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@if ($site->id)
@section('javascript')
    @parent
        (function () {
            document.querySelectorAll('.wpc-copy').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = document.getElementById(btn.getAttribute('data-target'));
                    input.select();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(input.value);
                    } else {
                        document.execCommand('copy');
                    }
                });
            });
            document.querySelectorAll('form.wpc-confirm').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    if (!window.confirm(form.getAttribute('data-confirm'))) {
                        e.preventDefault();
                    }
                });
            });
        })();
@endsection
@endif
