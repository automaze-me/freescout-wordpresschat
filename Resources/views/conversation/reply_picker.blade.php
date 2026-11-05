{{--
    The per-reply channel picker (spec 8.3), rendered at core's
    `reply_form.after` hook. That hook fires OUTSIDE the reply <form>, and
    core posts $(".form-reply:first").serialize(), which only walks the form's
    descendants. Public/js/wordpresschat.js therefore copies the checked radio
    into a hidden `wpc_channel` input inside the form. Variables: $default
    ('chat'|'email'), $emailDisabled, $chatDisabled.
--}}
<div class="wpc-reply-picker form-inline" data-wpc-picker="1">
    <span class="text-help">{{ __('Send via:') }}</span>
    <label class="radio-inline{{ $chatDisabled ? ' wpc-disabled' : '' }}">
        <input type="radio" name="wpc_channel_choice" value="chat"{{ $default === 'chat' ? ' checked' : '' }}{{ $chatDisabled ? ' disabled' : '' }}>
        {{ __('Website chat') }}
    </label>
    <label class="radio-inline{{ $emailDisabled ? ' wpc-disabled' : '' }}">
        <input type="radio" name="wpc_channel_choice" value="email"{{ $default === 'email' ? ' checked' : '' }}{{ $emailDisabled ? ' disabled' : '' }}>
        {{ __('Email') }}
    </label>
    @if ($emailDisabled)
        <span class="text-help wpc-picker-hint">{{ __('No email address is known for this visitor.') }}</span>
    @endif
    @if ($chatDisabled)
        <span class="text-help wpc-picker-hint">{{ __('The visitor continues by email.') }}</span>
    @endif
</div>
