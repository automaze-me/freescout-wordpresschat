<div class="wpc-chat-header" style="margin: 0 0 10px; padding: 0 20px;">
    <span class="label label-primary">{{ __('Website chat') }}@if ($siteName !== null) &middot; {{ $siteName }}@endif</span>
    <span class="label label-default">{{ $label }}</span>
    @if ($showWarn)
        <div class="alert alert-warning" style="margin: 8px 0 0;">{{ __("Linked by order number only. Don't share personal details in the chat.") }}</div>
    @endif
</div>
