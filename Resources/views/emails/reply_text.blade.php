@include(\Modules\WordPressChat\Services\ChatEmail::innerTextTemplate())
@if (!empty(\Modules\WordPressChat\Services\ChatEmail::current()['link']))

{!! str_repeat('-', 59) !!}
{!! __('Continue this chat on the website') !!}:
{!! \Modules\WordPressChat\Services\ChatEmail::current()['link'] !!}
@endif
