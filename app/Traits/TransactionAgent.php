<?php

namespace App\Traits;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\BasicSettings;
use App\Models\AgentNotification;
use App\Models\TemporaryData;
use App\Notifications\User\AddMoney\ApprovedMail;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;

trait TransactionAgent {
    public function createTransactionChildRecords($output,$status = PaymentGatewayConst::STATUSSUCCESS) {
        $basic_setting = BasicSettings::first();
        if($this->predefined_user) {
            $user = $this->predefined_user;
        }elseif(Auth::guard(userGuard()['guard'])->check()){
            $user = auth()->guard(userGuard()['guard'])->user();
        }
        $trx_id = 'AM'.getTrxNum();
        $inserted_id = $this->insertRecordAgent($output,$trx_id,$status);
        $this->insertChargesAgent($output,$inserted_id);
        $this->insertDeviceAgent($output,$inserted_id);
        // $this->removeTempDataAgent($output);
        if($this->requestIsApiUser()) {
            // logout user
            $api_user_login_guard = $this->output['api_login_guard'] ?? null;
            if($api_user_login_guard != null) {
                auth()->guard($api_user_login_guard)->logout();
            }
        }
        if( $basic_setting->email_notification == true){
            $user->notify(new ApprovedMail($user,$output,$trx_id));
        }
    }

    public function insertRecordAgent($output,$trx_id,$status) {
        $trx_id = $trx_id;
        DB::beginTransaction();
        try{
            if($this->predefined_user) {
                $user = $this->predefined_user;
            }elseif(Auth::guard(userGuard()['guard'])->check()){
                $user = auth()->guard(userGuard()['guard'])->user();
            }
            $id = DB::table("transactions")->insertGetId([
                'agent_id'                      => $user->id,
                'agent_wallet_id'               => $output['wallet']->id,
                'payment_gateway_currency_id'   => $output['currency']->id,
                'type'                          =>  "ADD-MONEY",
                'trx_id'                        => $trx_id,
                'request_amount'                => $output['amount']->requested_amount,
                'payable'                       => $output['amount']->total_amount,
                'available_balance'             => $output['wallet']->balance + $output['amount']->requested_amount,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::TYPEADDMONEY," ")) . " With " . $output['gateway']->name,
                'details'                       => $output['currency']->name." Payment Successful",
                'status'                        => $status,
                'attribute'                      =>PaymentGatewayConst::SEND,
                'callback_ref'                  => $output['callback_ref'] ?? null,
                'created_at'                    => now(),
            ]);
            if($status === PaymentGatewayConst::STATUSSUCCESS) {
                $this->updateWalletBalanceAgent($output);
            }
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    public function updateWalletBalanceAgent($output) {
        $update_amount = $output['wallet']->balance + $output['amount']->requested_amount;
        $output['wallet']->update([
            'balance'   => $update_amount,
        ]);
    }
    public function insertChargesAgent($output,$id) {

        if($this->predefined_user) {
            $user = $this->predefined_user;
        }elseif(Auth::guard(userGuard()['guard'])->check()){
            $user = auth()->guard(userGuard()['guard'])->user();
        }
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $output['amount']->percent_charge,
                'fixed_charge'      => $output['amount']->fixed_charge,
                'total_charge'      => $output['amount']->total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();

            //notification
            $notification_content = [
                'title'         => __("Add Money"),
                'message'       => __("Your Wallet")." (".$output['wallet']->currency->code.")  ".__("balance  has been added")." ".$output['amount']->requested_amount.' '. $output['wallet']->currency->code,
                'time'          => Carbon::now()->diffForHumans(),
                'image'         => get_image($user->image,'agent-profile'),
            ];

            AgentNotification::create([
                'type'      => NotificationConst::BALANCE_ADDED,
                'agent_id'  =>   $user->id,
                'message'   => $notification_content,
            ]);

            //admin notification
            $notification_content['title'] = __('Add Money').' '.$output['amount']->requested_amount.' '.$output['amount']->default_currency.' '.__(' By '). $output['currency']->name.' ('.$user->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::BALANCE_ADDED,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    public function insertDeviceAgent($output,$id) {
        $client_ip = request()->ip() ?? false;
        $location = geoip()->getLocation($client_ip);
        $agent = new Agent();

        // $mac = exec('getmac');
        // $mac = explode(" ",$mac);
        // $mac = array_shift($mac);
        $mac = "";

        DB::beginTransaction();
        try{
            DB::table("transaction_devices")->insert([
                'transaction_id'=> $id,
                'ip'            => $client_ip,
                'mac'           => $mac,
                'city'          => $location['city'] ?? "",
                'country'       => $location['country'] ?? "",
                'longitude'     => $location['lon'] ?? "",
                'latitude'      => $location['lat'] ?? "",
                'timezone'      => $location['timezone'] ?? "",
                'browser'       => $agent->browser() ?? "",
                'os'            => $agent->platform() ?? "",
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    public function removeTempDataAgent($output) {
        TemporaryData::where("identifier",$output['tempData']['identifier'])->delete();
    }

}
