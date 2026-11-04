@extends('oauth._layout', ['title' => __('oauth.title', ['client' => $client->name])])

@section('content')
    <h1>{{ __('oauth.title', ['client' => $client->name]) }}</h1>
    <p>{!! __('oauth.intro', ['client' => '<strong>'.e($client->name).'</strong>', 'host' => '<strong>'.e($redirectHost).'</strong>', 'user' => e($subject->email)]) !!}</p>
    @if ($isLoopback)
        <p class="warn" role="note">{{ __('oauth.loopback_warning') }}</p>
    @endif

    <form method="POST" action="{{ url('/oauth/authorize') }}" data-testid="oauth-consent-form">
        @csrf
        <input type="hidden" name="request_id" value="{{ $requestId }}">

        <fieldset>
            <legend>{{ __('oauth.workspaces') }}</legend>
            <p class="muted">{{ __('oauth.workspaces_help') }}</p>
            @foreach ($workspaces as $workspace)
                <label class="check">
                    <input type="checkbox" name="workspace_ids[]" value="{{ $workspace->id }}" @checked(in_array($workspace->id, $old['workspace_ids'], true)) data-testid="oauth-ws-{{ $workspace->slug }}">
                    <span>{{ $workspace->name }} <span class="muted">({{ $workspace->slug }}@if ($tenants->count() > 1) · {{ $tenants->get($workspace->tenant_id)?->name }}@endif)</span></span>
                </label>
            @endforeach
            @isset($formErrors['workspaces'])<p class="error">{{ $formErrors['workspaces'] }}</p>@endisset
        </fieldset>

        <fieldset>
            <legend>{{ __('oauth.access') }}</legend>
            <label class="check"><input type="checkbox" checked disabled> <span>{{ __('oauth.scope_read') }}</span></label>
            <label class="check"><input type="checkbox" name="write" value="1" @checked($old['write']) data-testid="oauth-write"> <span>{{ __('oauth.scope_write') }}</span></label>
            <label for="write_paths">{{ __('oauth.write_paths') }}</label>
            <textarea id="write_paths" name="write_paths">{{ $old['write_paths'] }}</textarea>
            <p class="muted">{{ __('oauth.write_paths_help') }}</p>
            @isset($formErrors['write_paths'])<p class="error">{{ $formErrors['write_paths'] }}</p>@endisset
            <label for="client_slug">{{ __('oauth.client_slug') }}</label>
            <input id="client_slug" type="text" name="client_slug" value="{{ $old['client_slug'] }}" maxlength="40">
            @isset($formErrors['client'])<p class="error">{{ $formErrors['client'] }}</p>@endisset
            @isset($formErrors['scopes'])<p class="error">{{ $formErrors['scopes'] }}</p>@endisset
        </fieldset>

        <label for="expires">{{ __('oauth.expires') }}</label>
        <select id="expires" name="expires">
            @foreach ($expiryOptions as $option)
                <option value="{{ $option }}" @selected($old['expires'] === $option)>{{ __('oauth.expiry.'.$option) }}</option>
            @endforeach
        </select>
        @isset($formErrors['expires'])<p class="error">{{ $formErrors['expires'] }}</p>@endisset

        <p class="muted">{{ __('oauth.revoke_hint') }}</p>
        <div class="actions">
            <button type="submit" name="decision" value="deny" data-testid="oauth-deny">{{ __('oauth.deny') }}</button>
            <button type="submit" name="decision" value="approve" class="primary" data-testid="oauth-approve">{{ __('oauth.approve') }}</button>
        </div>
    </form>
@endsection
