@extends('admin::layouts.master')

@section('page_title')
    {{ __('admin::app.settings.users.title') }}
@stop

@section('content-wrapper')
    <div class="content full-page dashboard">
        <div class="page-header">
            <div class="page-title">
                <h1>{{ __('admin::app.settings.users.title') }}</h1>
            </div>

            <div class="page-action">
            </div>
        </div>

        <div class="page-content">
        </div>
    </div>
@stop