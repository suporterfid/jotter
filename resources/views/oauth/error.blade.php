@extends('oauth._layout', ['title' => __('oauth.error_title')])

@section('content')
    <h1>{{ __('oauth.error_title') }}</h1>
    <p>{{ $message }}</p>
@endsection
