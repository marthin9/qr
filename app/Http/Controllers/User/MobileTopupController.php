<?php

namespace App\Http\Controllers\User;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\Currency;
use App\Models\Admin\TransactionSetting;
use App\Models\TopupCategory;
use App\Models\Transaction;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\User\MobileTopup\TopupMail;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\User\NotificationEvent as UserNotificationEvent;

class MobileTopupController extends Controller
{
    public function index() {
        $page_title = __("Mobile Topup");
        $topupCharge = TransactionSetting::where('slug','mobile_topup')->where('status',1)->first();
        $topupType = TopupCategory::active()->orderByDesc('id')->get();
        $transactions = Transaction::auth()->mobileTopup()->latest()->take(10)->get();
        return view('user.sections.mobile-top.index',compact("page_title",'topupCharge','transactions','topupType'));
    }
    public function payConfirm(Request $request){
        $request->validate([
            'topup_type' => 'required|string',
            'mobile_number' => 'required|min:10|max:13',
            'amount' => 'required|numeric|gt:0',

        ]);
        $basic_setting = BasicSettings::first();
        $user = auth()->user();
        if($basic_setting->kyc_verification){
            if( $user->kyc_verified == 0){
                return redirect()->route('user.profile.index')->with(['error' => [__('Please submit kyc information!')]]);
            }elseif($user->kyc_verified == 2){
                return redirect()->route('user.profile.index')->with(['error' => [__('Please wait before admin approves your kyc information')]]);
            }elseif($user->kyc_verified == 3){
                return redirect()->route('user.profile.index')->with(['error' => [__('Admin rejected your kyc information, Please re-submit again')]]);
            }
        }
        $amount = $request->amount;
        $topUpType = $request->topup_type;
        $topup_type = TopupCategory::where('id', $topUpType)->first();
        $mobile_number = $request->mobile_number;
        $user = auth()->user();
        $topupCharge = TransactionSetting::where('slug','mobile_topup')->where('status',1)->first();
        $userWallet = UserWallet::where('user_id',$user->id)->first();
        if(!$userWallet){
            return back()->with(['error' => [__('User Wallet not found')]]);
        }
        $baseCurrency = Currency::default();
        if(!$baseCurrency){
            return back()->with(['error' => [__('Default currency not found')]]);
        }
        $rate = $baseCurrency->rate;
        $minLimit =  $topupCharge->min_limit *  $rate;
        $maxLimit =  $topupCharge->max_limit *  $rate;
        if($amount < $minLimit || $amount > $maxLimit) {
            return back()->with(['error' => [__("Please follow the transaction limit")]]);
        }
        //charge calculations
        $fixedCharge = $topupCharge->fixed_charge *  $rate;
        $percent_charge = ($request->amount / 100) * $topupCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $amount;
        if($payable > $userWallet->balance ){
            return back()->with(['error' => [__('Sorry, insufficient balance')]]);
        }
        try{
            $trx_id = 'MP'.getTrxNum();
            $user = auth()->user();
            $sender = $this->insertSender( $trx_id,$user,$userWallet,$amount, $topup_type, $mobile_number,$payable);
            $this->insertSenderCharges( $fixedCharge,$percent_charge, $total_charge, $amount,$user,$sender);
            if( $basic_setting->email_notification == true){
                //send notifications
                $notifyData = [
                    'trx_id'  => $trx_id,
                    'topup_type'  => @$topup_type->name,
                    'mobile_number'  => $mobile_number,
                    'request_amount'   => $amount,
                    'charges'   => $total_charge,
                    'payable'  => $payable,
                    'current_balance'  => getAmount($userWallet->balance, 4),
                    'status'  => "Pending",
                ];
                $user->notify(new TopupMail($user,(object)$notifyData));
            }
            return redirect()->route("user.mobile.topup.index")->with(['success' => [__('Mobile topup request send to admin successful')]]);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

    }
    public function insertSender( $trx_id,$user,$userWallet,$amount, $topup_type, $mobile_number,$payable) {
        $trx_id = $trx_id;
        $authWallet = $userWallet;
        $afterCharge = ($authWallet->balance - $payable);
        $details =[
            'topup_type_id' => $topup_type->id??'',
            'topup_type_name' => $topup_type->name??'',
            'mobile_number' => $mobile_number,
            'topup_amount' => $amount??"",
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                       => $user->id,
                'user_wallet_id'                => $authWallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::MOBILETOPUP,
                'trx_id'                        => $trx_id,
                'request_amount'                => $amount,
                'payable'                       => $payable,
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::MOBILETOPUP," ")) . " Request To Admin",
                'details'                       => json_encode($details),
                'attribute'                      =>PaymentGatewayConst::SEND,
                'status'                        => 2,
                'created_at'                    => now(),
            ]);
            $this->updateSenderWalletBalance($authWallet,$afterCharge);

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    public function updateSenderWalletBalance($authWalle,$afterCharge) {
        $authWalle->update([
            'balance'   => $afterCharge,
        ]);
    }
    public function insertSenderCharges($fixedCharge,$percent_charge, $total_charge, $amount,$user,$id) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $percent_charge,
                'fixed_charge'      =>$fixedCharge,
                'total_charge'      =>$total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();

            //notification
            $notification_content = [
                'title'         =>__("Mobile Topup"),
                'message'       => __('Mobile topup request send to admin')." " .$amount.' '.get_default_currency_code()." ".__("Successful"),
                'image'         => get_image($user->image,'user-profile'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::MOBILE_TOPUP,
                'user_id'  => $user->id,
                'message'   => $notification_content,
            ]);

            event(new UserNotificationEvent($notification_content,$user));
            send_push_notification(["user-".$user->id],[
                'title'     => $notification_content['title'],
                'body'      => $notification_content['message'],
                'icon'      => $notification_content['image'],
            ]);

           //admin notification
           $notification_content['title'] = __("Mobile topup request send to admin")." ".$amount.' '.get_default_currency_code().' '.__("Successful").' ('.$user->username.')';
           AdminNotification::create([
               'type'      => NotificationConst::MOBILE_TOPUP,
               'admin_id'  => 1,
               'message'   => $notification_content,
           ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
}
