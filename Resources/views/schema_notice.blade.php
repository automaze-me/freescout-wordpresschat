@if (!\Modules\WordPressChat\Services\SchemaGuard::ok())
    <div class="alert alert-danger margin-top">
        {{ __('Website Chat needs a database update. Open System → Tools and click Migrate DB, then restart the queue workers.') }}
        <a href="{{ route('system.tools') }}" class="btn btn-danger btn-xs margin-left-10">{{ __('Migrate DB') }}</a>
    </div>
@endif
