<?php

namespace App\Http\Resources\Merchant;

use Illuminate\Http\Resources\Json\JsonResource;

class PayLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $statusInfo = [
            "success" =>      1,
            "pending" =>      2,
            "rejected" =>     3,
        ];
        return[
            'id'                    => $this->id,
            'trx'                   => $this->trx_id,
            'transaction_type'      => $this->type,
            'request_amount'        => get_amount($this->request_amount, @$this->details->charge_calculation->sender_cur_code),
            'payable'               => get_amount($this->details->charge_calculation->conversion_payable,  @$this->details->charge_calculation->receiver_currency_code),
            'exchange_rate'         => '1 ' .@$this->details->charge_calculation->sender_cur_code.' = '.get_amount(@$this->details->charge_calculation->exchange_rate, @$this->details->charge_calculation->receiver_currency_code),
            'total_charge'          => get_amount(@$this->details->charge_calculation->conversion_charge ?? 0, $this->merchant_wallet->currency->currency_code, 4),
            'current_balance'       => get_amount($this->available_balance, @$this->details->charge_calculation->receiver_currency_code),
            'status'                => $this->stringStatus->value,
            'date_time'             => $this->created_at,
            'status_info'           => (object)$statusInfo,
        ];
    }
}
