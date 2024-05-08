@extends('merchant.layouts.master')

@push('css')
    <style>
        .copy-button {
            cursor: pointer;
        }
    </style>
@endpush

@section('breadcrumb')
    @include('merchant.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("merchant.dashboard"),
        ]
    ], 'active' => __($page_title)])
@endsection

@section('content')
<div class="body-wrapper">
    <div class="row mb-20-none">
        <div class="col-xl-12 col-lg-12 mb-20">
            <div class="custom-card mt-10">
                <div class="dashboard-header-wrapper">
                    <h5 class="title">{{ __("developer API") }}</h5>
                </div>


                @if (auth()->user()->developerApi)
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="dash-payment-item-wrapper">
                            <div class="dash-payment-item active">
                                <div class="dash-payment-title-area justify-content-between align-items-center d-sm-flex d-block">
                                    <div class="payment-badge-wrapper d-flex align-items-center">
                                        <span class="dash-payment-badge">!</span>
                                    @if (auth()->user()->developerApi->mode == payment_gateway_const()::ENV_PRODUCTION)
                                        <h5 class="title">{{ __("Production") }}</h5><small class="text--base ms-1">({{ __("Activated") }})</small>
                                    @else
                                        <h5 class="title">{{ __("Sandbox") }}</h5><small class="text--base ms-1">({{ __("Activated") }})</small>
                                    @endif
                                    </div>
                                    @if (auth()->user()->developerApi->mode == payment_gateway_const()::ENV_SANDBOX)
                                    <button type="button" class="btn--base bg--warning active-deactive-btn mt-3 mt-sm-0">{{ __("production Live") }}</button>
                                    @else
                                        <button type="button" class="btn--base active-deactive-btn mt-3 mt-sm-0">{{ __("production Sand Box") }}</button>
                                    @endif
                                </div>
                                <div class="card-body">
                                    <form class="card-form">
                                        <div class="row">
                                            <div class="col-xl-12 col-lg-12 form-group">
                                                <label>{{ __("Client ID") }}</label>
                                                <div class="input-group">
                                                    <input type="text" id="client_id" class="form--control copiable" value="{{ auth()->user()->developerApi->client_id ?? "" }}" readonly>
                                                    <div class="input-group-text copy-button copy-primary">
                                                        <i class="las la-copy"></i>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-12 col-lg-12 form-group">
                                                <label>{{ __("Secret ID") }}</label>
                                                <div class="input-group">
                                                    <input type="text" id="secret_id" class="form--control copiable" value="{{ auth()->user()->developerApi->client_secret ?? "" }}" readonly>
                                                    <div class="input-group-text copy-button copy-secret"><i class="las la-copy"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

            </div>
        </div>
    </div>
</div>
@endsection

@push('script')
    <script>
        $(".active-deactive-btn").click(function(){
            var actionRoute =  "{{ setRoute('merchant.developer.api.mode.update') }}";
            var target      = 1;
            var btnText     = $(this).text();
            var firstText     = "{{ __('Are you sure change mode to') }}";
            var message     = `${firstText} <strong>${btnText}</strong>?`;
            openAlertModal(actionRoute,target,message,btnText,"POST");
        });

        //primary key copy
        $('.copy-primary').on('click',function(){
                var copyText = document.getElementById("client_id");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                document.execCommand("copy");
                var message     = "{{ __('Copied Client ID') }}";
                throwMessage('success',[message]);
        });
        //Secret  key copy
        $('.copy-secret').on('click',function(){
                var copyText = document.getElementById("secret_id");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                document.execCommand("copy");
                var message     = "{{ __('Copied Secret ID') }}";
                throwMessage('success',[message]);
        });
    </script>
@endpush
