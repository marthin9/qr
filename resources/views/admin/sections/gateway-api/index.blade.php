@extends('admin.layouts.master')

@push('css')

@endpush

@section('page-title')
    @include('admin.components.page-title',['title' => __($page_title)])
@endsection

@section('breadcrumb')
    @include('admin.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("admin.dashboard"),
        ]
    ], 'active' => __("PayLink Api")])
@endsection

@section('content')
    <div class="custom-card">
        <div class="card-header">
            <h6 class="title">{{ __("Stripe API") }}</h6>
        </div>
        <div class="card-body">
            <form class="card-form" action="{{ route('admin.gateway.api.update') }}" method="POST">
                @csrf
                @method("PUT")
                <div class="row mb-10-none">
                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 form-group">
                        <label>{{ __("secret Key") }}*</label>
                        <div class="input-group append">
                            <span class="input-group-text"><i class="las la-key"></i></span>
                            <input type="text" class="form--control" name="secret_key" value="{{ @$api->secret_key }}" required value="{{ old('secret_key') }}">
                        </div>
                    </div>
                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 form-group">
                        <label>{{ __("Public Key*") }}</label>
                        <div class="input-group append">
                            <span class="input-group-text"><i class="las la-globe-europe"></i></span>
                            <input type="text" class="form--control" name="public_key" value="{{ @$api->public_key }}" required value="{{ old('public_key') }}">
                        </div>
                    </div>
                    <div class="col-xl-12 col-lg-12 form-group">
                        @include('admin.components.button.form-btn',[
                            'class'         => "w-100 btn-loading",
                            'text'          => __("update"),
                            'permission'    => "admin.virtual.card.api.update"
                        ])
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
