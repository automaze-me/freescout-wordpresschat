<div class="wpc-chat-header">
    @if ($siteName !== null)
        <span class="label label-default">{{ $siteName }}</span>
    @endif
    <span class="label label-default">{{ $label }}</span>
    @if ($presence !== null)
        <div class="wpc-presence text-help">{{ $presence }}</div>
    @endif
    @if ($showWarn)
        <div class="alert alert-warning wpc-order-warning">{{ __("Linked by order number only. Don't share personal details in the chat.") }}</div>
    @endif
</div>
