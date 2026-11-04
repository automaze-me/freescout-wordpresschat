<div class="wpc-chat-header" style="margin: 0 0 10px; padding: 0 20px;">
    @if ($siteName !== null)
        <span class="label label-default">{{ $siteName }}</span>
    @endif
    <span class="label label-default">{{ $label }}</span>
    @if ($presence !== null)
        <div class="wpc-presence text-help" style="margin: 6px 0 0;">{{ $presence }}</div>
    @endif
    @if ($showWarn)
        <div class="alert alert-warning" style="margin: 8px 0 0;">{{ __("Linked by order number only. Don't share personal details in the chat.") }}</div>
    @endif
</div>
