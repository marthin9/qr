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
    ], 'active' => __("request Money Log")])
@endsection

@section('content')
<div class="table-area">
    <div class="table-wrapper">
        <div class="table-header">
            <h5 class="title">{{ $page_title }}</h5>
            @if(count($transactions) > 0)
            <div class="table-btn-area">
                <a href="{{ setRoute('admin.request.money.export.data') }}" class="btn--base py-2 px-4"><i class="fas fa-download me-1"></i>{{ __("Export Data") }}</a>
            </div>
        @endif
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>{{ __("TRX ID") }}</th>
                        <th>{{ __("User Type") }}</th>
                        <th>{{ __("Requested From") }}</th>
                        <th>{{ __("Requested To") }}</th>
                        <th>{{ __("request Amount") }}</th>
                        <th>{{ __("Receiver Amount") }}</th>
                        <th>{{ __("charge") }}</th>
                        <th>{{ __("Payable") }}</th>
                        <th>{{ __(("Status")) }}</th>
                        <th>{{ __("Time") }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions ?? []  as $key => $item)
                        <tr>
                            <td>{{ $item->trx_id }}</td>
                            <td>
                                {{ __("USER") }}
                            </td>
                            <td>
                                <a href="{{ setRoute('admin.users.details',$item->creator->username) }}">{{ $item->creator->email }}</a>
                            </td>
                            <td>
                                <a href="{{ setRoute('admin.users.details',$item->details->receiver_username) }}">{{ $item->details->receiver_email }}</a>
                            </td>
                            <td>{{ get_amount($item->details->charges->request_amount,$item->details->charges->sender_currency,2) }}</td>
                            <td>{{ get_amount($item->details->charges->receiver_amount,$item->details->charges->receiver_currency,2) }}</td>
                            <td>{{ get_amount($item->details->charges->total_charge,$item->details->charges->sender_currency,2) }}</td>
                            <td>{{ get_amount($item->details->charges->payable,$item->details->charges->sender_currency,2) }}</td>
                            <td>
                                <span class="{{ $item->stringStatus->class }}">{{ __($item->stringStatus->value) }}</span>
                            </td>
                            <td>{{ $item->created_at->format('d-m-y h:i:s A') }}</td>

                        </tr>
                    @empty
                         @include('admin.components.alerts.empty',['colspan' => 11])
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ get_paginate($transactions) }}
    </div>
</div>
@endsection

@push('script')

@endpush
