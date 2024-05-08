<?php

namespace App\Http\Controllers\Agent;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\TransactionSetting;
use App\Models\AgentNotification;
use App\Models\AgentWallet;
use App\Models\TopupCategory;
use App\Models\Transaction;
use App\Notifications\User\MobileTopup\TopupMail;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MobileTopupController extends Controller
{
    public function index() {
        $page_title = __("Mobile Topup");
        $topupCharge = TransactionSetting::where('slug','mobile_topup')->where('status',1)->first();
        $topupType = TopupCategory::active()->orderByDesc('id')->get();
        $transactions = Transaction::agentAuth()->mobileTopup()->latest()->take(10)->get();
        return view('agent.sections.mobile-top.index',compact("page_title",'topupCharge','transactions','topupType'));
    }
    public function topUpConfirmed(Request $request){
        $validated = Validator::make($request->all(),[
            'topup_type' => 'required|exists:topup_categories,id',
            'mobile_code' => 'required|max:6',
            'mobile_number' => 'required|min:10|max:13',
            'amount' => 'required|numeric|gt:0',
        ])->validate();

        $basic_setting = BasicSettings::first();
        $user =  authGuardApi()['user'];
        $phone = remove_speacial_char($validated['mobile_code']).$validated['mobile_number'];

        $sender_wallet = AgentWallet::auth()->active()->first();
        if(!$sender_wallet){
            return back()->with(['error' => [__('Agent wallet not found')]]);
        }
        $topup_type = TopupCategory::where('id', $validated['topup_type'])->first();
        if(! $topup_type){
            return back()->with(['error' => [__('Invalid type')]]);
        }
        $topupCharge = TransactionSetting::where('slug','mobile_topup')->where('status',1)->first();
        $charges = $this->topupCharge($validated['amount'],$topupCharge,$sender_wallet);

        $sender_currency_rate = $sender_wallet->currency->rate;
        $min_amount = $topupCharge->min_limit * $sender_currency_rate;
        $max_amount = $topupCharge->max_limit * $sender_currency_rate;

        if($charges['sender_amount'] < $min_amount || $charges['sender_amount'] > $max_amount) {
            return back()->with(['error' => [__("Please follow the transaction limit")]]);
        }
        if($charges['payable'] > $sender_wallet->balance) {
            return back()->with(['error' => [__("Sorry, insufficient balance")]]);
        }
        try{
            $trx_id = 'MP'.getTrxNum();
            $sender = $this->insertSender($trx_id,$sender_wallet, $charges,$topup_type,$phone);
            $this->insertSenderCharges($sender,$charges,$sender_wallet);
            if( $basic_setting->agent_email_notification == true){
                //send notifications
                $notifyData = [
                    'trx_id'  => $trx_id,
                    'topup_type'  => @$topup_type->name,
                    'mobile_number'  => $phone,
                    'request_amount'   => $charges['sender_amount'],
                    'charges'   => $charges['total_charge'],
                    'payable'  => $charges['payable'],
                    'current_balance'  => getAmount($sender_wallet->balance, 4),
                    'status'  => "Pending",
                ];
                $user->notify(new TopupMail($user,(object)$notifyData));
            }
            return back()->with(['success' => [__("Mobile topup request send to admin successful")]]);
        }catch(Exception $e) {
           return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

    }
    public function insertSender($trx_id,$sender_wallet, $charges, $topup_type,$mobile_number) {
        $trx_id = $trx_id;
        $authWallet = $sender_wallet;
        $afterCharge = ($authWallet->balance -  $charges['payable']);
        $details =[
            'topup_type_id' => $topup_type->id??'',
            'topup_type_name' => $topup_type->name??'',
            'mobile_number' => $mobile_number,
            'topup_amount' =>$charges['sender_amount']??"",
            'charges' => $charges,
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'agent_id'                      => $sender_wallet->agent->id,
                'agent_wallet_id'               => $sender_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::MOBILETOPUP,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges['sender_amount'],
                'payable'                       => $charges['payable'],
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::MOBILETOPUP," ")) . "  Request To Admin",
                'details'                       => json_encode($details),
                'attribute'                      =>PaymentGatewayConst::SEND,
                'status'                        => PaymentGatewayConst::STATUSPENDING,
                'created_at'                    => now(),
            ]);
            $this->updateSenderWalletBalance($authWallet,$afterCharge);

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
           return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
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
                'title'         =>__("Mobile Topup"),
                'message'       => __('Mobile topup request send to admin')." " .$charges['sender_amount'].' '.$charges['sender_currency']." ".__("Successful"),
                'image'         => get_image($sender_wallet->agent->image,'agent-profile'),
            ];

            AgentNotification::create([
                'type'      => NotificationConst::MOBILE_TOPUP,
                'agent_id'  => $sender_wallet->agent->id,
                'message'   => $notification_content,
            ]);

           //admin notification
           $notification_content['title'] =__("Mobile topup request send to admin")." ".$charges['sender_amount'].' '.$charges['sender_currency'].' Successful ('.$sender_wallet->agent->username.')';
           AdminNotification::create([
               'type'      => NotificationConst::MOBILE_TOPUP,
               'admin_id'  => 1,
               'message'   => $notification_content,
           ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
           return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    public function topupCharge($sender_amount,$charges,$sender_wallet) {
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
