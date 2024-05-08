@extends('frontend.layouts.master')

@php
    $defualt = get_default_language_code()??'en';
    $default_lng = 'en';
@endphp

@push('css')
    <style>
        card-errors {
            height: 20px;
            padding: 4px 0;
            color: #fa755a;
        }

        #stripe-token-handler {
            position: absolute;
            top: 0;
            left: 25%;
            right: 25%;
            padding: 20px 30px;
            border-radius: 0 0 4px 4px;
            box-sizing: border-box;
            box-shadow: 0 50px 100px rgba(50, 50, 93, 0.1),
                0 15px 35px rgba(50, 50, 93, 0.15),
                0 5px 15px rgba(0, 0, 0, 0.1);
            -webkit-transition: all 500ms ease-in-out;
            transition: all 500ms ease-in-out;
            transform: translateY(0);
            opacity: 1;
            background-color: white;
        }

        #stripe-token-handler.is-hidden {
            opacity: 0;
            transform: translateY(-80px);
        }

        #card-element {
            background-color: white;
            padding: 10px 12px;
            border-radius: 4px;
            border: 1px solid transparent;
            -webkit-transition: box-shadow 150ms ease;
            transition: box-shadow 150ms ease;
            height: 45px;
            line-height: 45px;
            font-weight: 500;
            border: 1px solid #e5e5e5;
            font-size: 14px;
            color: #425466;
            padding: 13px 15px;
            width: 100%;
        }

        #card-element--focus {
            border: 1px solid #5b39c9;
        }

        #card-element--invalid {
            border-color: #fa755a;
        }

        #card-element--webkit-autofill {
            background-color: #fefde5 !important;
        }
        @media only screen and (max-width: 1199px) {
            .payment-preview-wrapper{
                width: 1000px;
            }
        }
        @media only screen and (max-width: 991px) {
            .payment-preview-wrapper{
                width: 100%;
            }
            .payment-share-wrapper .payment-preview-box{
                display: block;
            }
        }
    </style>
@endpush

@section('content')
<div class="custom-card payment-card">
    <div class="payment-preview-wrapper payment-share-wrapper">
        <form id="payment-form" action="{{ setRoute('payment-link.submit') }}" method="POST">
            <div class="payment-preview-box">
                @csrf
                <input type="hidden" name="target" value="{{ $payment_link->id }}">
                <input type="hidden" name="token">
                <input type="hidden" name="last4_card">
                <div class="payment-preview-box-left">
                    <span class="sub-title"><i class="lab la-windows"></i> {{ @$payment_link->title }}

                        @if (@$payment_link->type == 'sub')
                            ({{ @$payment_link->qty }})
                        @endif

                    </span>
                    <div class="form-group">
                        <label>{{ __('Price') }}</label>
                        <div class="input-group">
                            <div class="input-group-text prepend">{{ @$payment_link->currency_symbol }}</div>

                            @if (@$payment_link->type == 'sub')
                                <input type="integer" name="amount" class="form--control" value="{{ $payment_link->amountValue }}" placeholder="0.00" readonly>
                            @else
                                @if ($payment_link->limit == 1)
                                    <input type="integer" name="amount" class="form--control" value="{{ number_format($payment_link->min_amount, 2, '.', '') }}" placeholder="0.00">
                                @else
                                    <input type="integer" name="amount" class="form--control" value="" placeholder="0.00">
                                @endif
                            @endif


                        </div>
                        @if ($payment_link->type ==  payment_gateway_const()::LINK_TYPE_PAY)
                            @if ($payment_link->limit == 1)
                                <span class="limit-show">{{ get_amount($payment_link->min_amount, @$payment_link->currency) }} - {{ get_amount($payment_link->max_amount, @$payment_link->currency) }}</span>
                            @endif
                        @endif
                    </div>
                    @if ($payment_link->type ==  payment_gateway_const()::LINK_TYPE_PAY)
                        <div class="payment-preview-thumb">
                            @if ($payment_link->image)
                                <img src="{{ get_image($payment_link->image,'payment-link-image') }}" alt="Link Image">
                            @else
                                <img src="{{ asset('public/frontend/images/logo/link_icon.png') }}" alt="Link Image">
                            @endif
                        </div>
                    @endif
                </div>
                <div class="payment-preview-box-right">
                    <div class="row">
                        <div class="col-xl-12 form-group">
                            <div class="or-area">
                                <span class="or-line"></span>
                                <span class="or-title">{{ __('Pay with Debit & Credit Card') }}</span>
                                <span class="or-line"></span>
                            </div>
                        </div>
                        <div class="col-xl-12 form-group">
                            <input type="email" class="form--control" name="email" placeholder="Email" name="email" value="{{ old('email') }}" required>
                        </div>

                        <div class="col-xl-12 form-group">
                            <input type="text" class="form--control" placeholder="Name On Card" name="card_name" value="{{ old('card_name') }}" required>
                        </div>

                        <div class="col-xl-12 form-group">
                            <div id="card-element">
                            </div>
                        </div>

                        <div class="col-xl-12 form-group">
                            <div class="preview-secure-group">
                                <img src="{{ asset('public/frontend/images/icon/100-percent.png') }}" alt="">
                                <p>{{ __('Securely save my inforation for 1-click checkout') }} <span>{{ __('Pay faster on') }} {{ @$payment_link->user->address->company_name }} {{ __('and everywhere Link is accepted') }}</span></p>
                            </div>
                        </div>
                        <div class="col-xl-12 form-group pt-10">
                            <button type="button" id="submit-button" class="btn--base w-100 btn-loading">{{ __("Pay") }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection


@push("script")
    <script src="https://js.stripe.com/v3/"></script>
    <script>
        // Create a Stripe client
        var stripe = Stripe('{{ $public_key }}');
        // Create an instance of Elements
        var elements = stripe.elements();

        var style = {
            base: {
                color: '#32325d',
                lineHeight: '18px',
                fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '16px',
                '::placeholder': {
                color: '#425466'
                }
            },
            invalid: {
                color: '#fa755a',
                iconColor: '#fa755a'
            }
        };

        // Create an instance of the card Element
        var card = elements.create('card', {
            hidePostalCode: true,
            style: style
        });

        // Add an instance of the card Element into the `card-element` <div>
        card.mount('#card-element');

        // Handle real-time validation errors from the card Element.
        card.addEventListener('change', function(event) {
            var displayError = document.getElementById('card-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = '';
            }
        });

        $('#submit-button').on('click', function () {
            var form = document.getElementById('payment-form');
            event.preventDefault();
            stripe.createToken(card).then(function(result) {
                if (result.error) {
                    notification('danger', result.error.message);
                } else {
                    $('#payment-form input[name="token"]').val(result.token.id);
                    $('#payment-form input[name="last4_card"]').val(result.token.card.last4);
                    if(result.token.id){
                        form.submit();
                    }else{
                        notification('danger', 'Something Went, Wrong Please Contact Support');
                    }
                }
            });
        });
    </script>
@endpush
