<?php

namespace App\Http\Controllers\Agent;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\TransactionSetting;
use App\Models\BillPayCategory;
use App\Models\Transaction;
use App\Notifications\User\BillPay\BillPayMail;
use App\Providers\Admin\BasicSettingsProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\AdminNotification;
use App\Models\AgentNotification;
use App\Models\AgentWallet;
use Illuminate\Support\Facades\Validator;

class BillPayController extends Controller
{
    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function index() {
        $page_title = __("Bill Pay");
        $billPayCharge = TransactionSetting::where('slug','bill_pay')->where('status',1)->first();
        $billType = BillPayCategory::active()->orderByDesc('id')->get();
        $transactions = Transaction::agentAuth()->billPay()->latest()->take(10)->get();
        return view('agent.sections.bill-pay.index',compact("page_title",'billPayCharge','transactions','billType'));
    }

    public function billPayConfirmed(Request $request){
        $validated = Validator::make($request->all(),[
            'amount'     => "required|numeric|gt:0",
            'bill_type'  => "required|exists:bill_pay_categories,id",
            'bill_number' => 'required|min:8',
        ])->validate();
        $user = authGuardApi()['user'];

        $sender_wallet = AgentWallet::auth()->active()->first();
        if(!$sender_wallet){
            return back()->with(['error' => [__('Agent wallet not found')]]);
        }
        $trx_charges = TransactionSetting::where('slug','bill_pay')->where('status',1)->first();;
        $charges = $this->billPayCharge($validated['amount'],$trx_charges,$sender_wallet);

        $bill_type = BillPayCategory::where('id', $validated['bill_type'])->first();
        if(!$bill_type){
            return back()->with(['error' => [__('Invalid bill type')]]);
        }
         // Check transaction limit
         $sender_currency_rate = $sender_wallet->currency->rate;
         $min_amount = $trx_charges->min_limit * $sender_currency_rate;
         $max_amount = $trx_charges->max_limit * $sender_currency_rate;
         if($charges['sender_amount'] < $min_amount || $charges['sender_amount'] > $max_amount) {
            return back()->with(['error' => [__("Please follow the transaction limit")]]);
         }
         if($charges['payable'] > $sender_wallet->balance) {
            return back()->with(['error' => [__("Sorry, insufficient balance")]]);
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
            return back()->with(['success' => [__("Bill pay request sent to admin successful")]]);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
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
           return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
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
