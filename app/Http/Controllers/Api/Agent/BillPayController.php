<?php

namespace App\Http\Controllers\Api\Agent;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Http\Helpers\Api\Helpers;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use App\Models\AgentNotification;
use App\Models\AgentWallet;
use App\Models\BillPayCategory;
use App\Models\Transaction;
use App\Notifications\User\BillPay\BillPayMail;
use App\Providers\Admin\BasicSettingsProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BillPayController extends Controller
{
    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function billPayInfo(){
        $user = authGuardApi()['user'];
        $userWallet = AgentWallet::where('agent_id',$user->id)->get()->map(function($data){
            return[
                'balance' => getAmount($data->balance,4),
                'currency' => get_default_currency_code(),
                'rate' => getAmount($data->currency->rate,4),
            ];
        })->first();
        $billPayCharge = TransactionSetting::where('slug','bill_pay')->where('status',1)->get()->map(function($data){
            return[
                'id' => $data->id,
                'slug' => $data->slug,
                'title' => $data->title,
                'fixed_charge' => getAmount($data->fixed_charge,2),
                'percent_charge' => getAmount($data->percent_charge,2),
                'min_limit' => getAmount($data->min_limit,2),
                'max_limit' => getAmount($data->max_limit,2),
                'monthly_limit' => getAmount($data->monthly_limit,2),
                'daily_limit' => getAmount($data->daily_limit,2),
                'agent_fixed_commissions' => getAmount($data->agent_fixed_commissions,2),
                'agent_percent_commissions' => getAmount($data->agent_percent_commissions,2),
                'agent_profit' => $data->agent_profit,
            ];
        })->first();
        $billType = BillPayCategory::active()->orderByDesc('id')->get();
        $transactions = Transaction::agentAuth()->billPay()->latest()->take(5)->get()->map(function($item){
            $statusInfo = [
                "success" =>      1,
                "pending" =>      2,
                "rejected" =>     3,
                ];
            return[
                'id' => $item->id,
                'trx' => $item->trx_id,
                'transaction_type' => $item->type,
                'request_amount' => getAmount($item->request_amount,2).' '.$item->details->charges->sender_currency ,
                'payable' => getAmount($item->payable,2).' '.$item->details->charges->sender_currency,
                'bill_type' =>$item->details->bill_type_name,
                'bill_number' =>$item->details->bill_number,
                'total_charge' => getAmount($item->charge->total_charge,2).' '.$item->details->charges->sender_currency,
                'current_balance' => getAmount($item->available_balance,2).' '.$item->details->charges->sender_currency,
                'status' => $item->stringStatus->value ,
                'date_time' => $item->created_at ,
                'status_info' =>(object)$statusInfo ,
                'rejection_reason' =>$item->reject_reason??"" ,
            ];
        });
        $data =[
            'base_curr' => get_default_currency_code(),
            'base_curr_rate' => get_default_currency_rate(),
            'billPayCharge'=> (object)$billPayCharge,
            'agentWallet'=>  (object)$userWallet,
            'billTypes'=>  $billType,
            'transactions'   => $transactions,
        ];
        $message =  ['success'=>[__('Bill Pay Information')]];
        return Helpers::success($data,$message);
    }
    public function billPayConfirmed(Request $request){
        $validator = Validator::make(request()->all(), [
            'amount'     => "required|numeric|gt:0",
            'bill_type'  => "required|exists:bill_pay_categories,id",
            'bill_number' => 'required|min:8',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated =  $validator->validate();
        $user = authGuardApi()['user'];

        $sender_wallet = AgentWallet::auth()->active()->first();
        if(!$sender_wallet){
            $error = ['error'=>[__('Agent wallet not found!')]];
            return Helpers::error($error);
        }
        $trx_charges = TransactionSetting::where('slug','bill_pay')->where('status',1)->first();;
        $charges = $this->billPayCharge($validated['amount'],$trx_charges,$sender_wallet);

        $bill_type = BillPayCategory::where('id', $validated['bill_type'])->first();
        if(!$bill_type){
            $error = ['error'=>[__('Invalid bill type')]];
            return Helpers::error($error);
        }
         // Check transaction limit
         $sender_currency_rate = $sender_wallet->currency->rate;
         $min_amount = $trx_charges->min_limit * $sender_currency_rate;
         $max_amount = $trx_charges->max_limit * $sender_currency_rate;
         if($charges['sender_amount'] < $min_amount || $charges['sender_amount'] > $max_amount) {
            $error = ['error'=>[__("Please follow the transaction limit")]];
             return Helpers::error($error);
         }
         if($charges['payable'] > $sender_wallet->balance) {
            $error = ['error'=>[__('Sorry, insufficient balance')]];
            return Helpers::error($error);
         }

        try{
            $trx_id = 'BP'.getTrxNum();
            $sender = $this->insertSender($trx_id,$sender_wallet, $charges, $bill_type,$validated['bill_number']);
            $this->insertSenderCharges($sender,$charges,$sender_wallet);
            if( $this->basic_settings->agent_email_notification == true){
                $notifyData = [
                    'trx_id'  => $trx_id,
                    'bill_type'  => @$bill_type->name,
                    'bill_number'  => @$validated['bill_number'],
                    'request_amount'   => $charges['sender_amount'],
                    'charges'   => $charges['total_charge'],
                    'payable'  => $charges['payable'],
                    'current_balance'  => getAmount($sender_wallet->balance, 4),
                    'status'  => "Pending",
                ];
                //send notifications
                $user->notify(new BillPayMail($user,(object)$notifyData));
            }
            $message =  ['success'=>[__('Bill pay request sent to admin successful')]];
            return Helpers::onlysuccess($message);
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }

    }
    public function insertSender( $trx_id,$sender_wallet, $charges, $bill_type,$bill_number) {
        $trx_id = $trx_id;
        $authWallet = $sender_wallet;
        $afterCharge = ($authWallet->balance -  $charges['payable']);
        $details =[
            'bill_type_id' => $bill_type->id??'',
            'bill_type_name' => $bill_type->name??'',
            'bill_number' => $bill_number,
            'bill_amount' => $charges['sender_amount']??"",
            'charges' => $charges,
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'agent_id'                      => $sender_wallet->agent->id,
                'agent_wallet_id'               => $sender_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::BILLPAY,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges['sender_amount'],
                'payable'                       => $charges['payable'],
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::BILLPAY," ")) . " Request To Admin",
                'details'                       => json_encode($details),
                'attribute'                     => PaymentGatewayConst::SEND,
                'status'                        => 2,
                'created_at'                    => now(),
            ]);
            $this->updateSenderWalletBalance($authWallet,$afterCharge);

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
           $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        return $id;
    }
    public function updateSenderWalletBalance($authWalle,$afterCharge) {
        $authWalle->update([
            'balance'   => $afterCharge,
        ]);
    }
    public function insertSenderCharges($id,$charges,$sender_wallet) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    =>  $id,
                'percent_charge'    =>  $charges['percent_charge'],
                'fixed_charge'      =>  $charges['fixed_charge'],
                'total_charge'      =>  $charges['total_charge'],
                'created_at'        =>  now(),
            ]);
            DB::commit();

            //notification
            $notification_content = [
                'title'         =>__("Bill Pay"),
                'message'       => __("Bill pay request send to admin")." " .$charges['sender_amount'].' '.$charges['sender_currency']." ".__("Successful"),
                'image'         => get_image($sender_wallet->agent->image,'agent-profile'),
            ];

            AgentNotification::create([
                'type'      => NotificationConst::BILL_PAY,
                'agent_id'  => $sender_wallet->agent->id,
                'message'   => $notification_content,
            ]);

           //admin notification
            $notification_content['title'] = __("Bill pay request send to admin")." ".$charges['sender_amount'].' '.$charges['sender_currency'].' '.__("Successful").' ('.$sender_wallet->agent->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::BILL_PAY,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
           $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    public function billPayCharge($sender_amount,$charges,$sender_wallet) {
        $data['sender_amount']                      = $sender_amount;
        $data['sender_currency']                    = $sender_wallet->currency->code;
        $data['sender_currency_rate']               = $sender_wallet->currency->rate;
        $data['percent_charge']                     = ($sender_amount / 100) * $charges->percent_charge ?? 0;
        $data['fixed_charge']                       = $sender_wallet->currency->rate * $charges->fixed_charge ?? 0;
        $data['total_charge']                       = $data['percent_charge'] + $data['fixed_charge'];
        $data['sender_wallet_balance']              = $sender_wallet->balance;
        $data['payable']                            = $sender_amount + $data['total_charge'];
        $data['agent_percent_commission']           = ($sender_amount / 100) * $charges->agent_percent_commissions ?? 0;
        $data['agent_fixed_commission']             = $sender_wallet->currency->rate * $charges->agent_fixed_commissions ?? 0;
        $data['agent_total_commission']             = $data['agent_percent_commission'] + $data['agent_fixed_commission'];
        return $data;
    }
}
