<?php

namespace App\Http\Controllers\User;

use App\Models\Receipient;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\Currency;
use App\Models\Admin\ReceiverCounty;
use App\Models\Admin\TransactionSetting;
use App\Models\Transaction;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\User\Remittance\BankTransferMail;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Events\User\NotificationEvent as UserNotificationEvent;
use App\Http\Helpers\Response;
use Illuminate\Support\Facades\Validator;

class RemitanceController extends Controller
{
    protected  $trx_id;
    public function __construct()
    {
        $this->trx_id = 'RT'.getTrxNum();
    }
    public function index() {
        $page_title =__( "Remittance");
        $exchangeCharge = TransactionSetting::where('slug','remittance')->where('status',1)->first();
        $receiverCountries = ReceiverCounty::active()->get();
        $receipients = Receipient::auth()->orderByDesc("id")->paginate(12);
        $transactions = Transaction::auth()->remitance()->latest()->take(5)->get();
        return view('user.sections.remittance.index',compact(
            "page_title",
            'exchangeCharge',
            'receiverCountries',
            'receipients',
            'transactions'
        ));
    }
    public function confirmed(Request $request){

        $validator = Validator::make($request->all(),[
            'form_country'               =>'required',
            'to_country'                 =>'required',
            'transaction_type'           =>'required|string',
            'recipient'                  =>'required',
            'send_amount'                =>"required|numeric",
            'receive_amount'             =>'required|numeric',
        ]);
        if($validator->fails()) {
            return Response::error($validator->errors());
        }
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
        $exchangeCharge = TransactionSetting::where('slug','remittance')->where('status',1)->first();
        $user = auth()->user();

        $userWallet = UserWallet::where('user_id',$user->id)->first();
        if(!$userWallet){
            return Response::error([__('User wallet not found')]);
        }
        $baseCurrency = Currency::default();
        if(!$baseCurrency){
            return Response::error([__('Default currency not found')]);
        }
        $to_country = ReceiverCounty::where('id',$request->to_country)->first();
        if(!$to_country){
            return Response::error([__('Receiver country not found')]);
        }
        $receipient = Receipient::auth()->where("id",$request->recipient)->first();
        if(!$receipient){
            return Response::error([__('Recipient is invalid')]);
        }

        $base_rate = $baseCurrency->rate;
        $receiver_rate =$to_country->rate;
        $form_country =  $baseCurrency->country;
        $send_amount = $request->send_amount;
        $receive_amount = $request->receive_amount;
        $transaction_type = $request->transaction_type;
        $minLimit =  $exchangeCharge->min_limit *  $base_rate;
        $maxLimit =  $exchangeCharge->max_limit *  $base_rate;
        if($send_amount < $minLimit || $send_amount > $maxLimit) {
            return Response::error([__("Please follow the transaction limit")]);
        }

        //charge calculations
        $fixedCharge = $exchangeCharge->fixed_charge *  $base_rate;
        $percent_charge = ($send_amount / 100) * $exchangeCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $send_amount;
        //receiver amount
        $receiver_rate = (float) $receiver_rate / (float)$base_rate;
        $receiver_amount = $receiver_rate * $send_amount;
        $receiver_will_get = $receiver_amount;
        if($payable > $userWallet->balance ){
            return Response::error([__('Sorry, insufficient balance')]);
        }

        try{
            if($transaction_type === Str::slug(GlobalConst::TRX_WALLET_TO_WALLET_TRANSFER)){
                $receiver_user =  json_decode($receipient->details);
                $receiver_user =  $receiver_user->id;
                $receiver_wallet = UserWallet::where('user_id',$receiver_user)->first();
                if(!$receiver_wallet){
                    return Response::error([__('Receiver wallet not found')]);
                }
                $trx_id = $this->trx_id;
                $sender = $this->insertSender( $trx_id,$user,$userWallet,$send_amount,$receiver_will_get,$payable,$receipient,$form_country,$to_country,$transaction_type);
                if($sender){
                     $this->insertSenderCharges( $fixedCharge,$percent_charge, $total_charge, $send_amount,$user,$sender,$receipient,$receiver_user);
                }
                $receiverTrans = $this->insertReceiver( $trx_id,$user,$userWallet,$send_amount,$receiver_will_get,$payable,$receipient,$form_country,$to_country,$transaction_type,$receiver_user,$receiver_wallet);
                if($receiverTrans){
                     $this->insertReceiverCharges(  $fixedCharge,$percent_charge, $total_charge, $send_amount,$user,$receiverTrans,$receipient,$receiver_user);
                }
                session()->forget('remittance_token');

            }else{
                $trx_id = $this->trx_id;
                $sender = $this->insertSender( $trx_id,$user,$userWallet,$send_amount,$receiver_will_get,$payable,$receipient,$form_country,$to_country,$transaction_type);
                if($sender){
                     $this->insertSenderCharges( $fixedCharge,$percent_charge, $total_charge, $send_amount,$user,$sender,$receipient);
                     session()->forget('remittance_token');
                }
                if( $basic_setting->email_notification == true){
                        $notifyData = [
                            'trx_id'  => $trx_id,
                            'title'  => "Send Remittance to @" . $receipient->firstname.' '.@$receipient->lastname." (".@$receipient->mobile_code.@$receipient->mobile.")",
                            'request_amount'  => getAmount($send_amount,4).' '.get_default_currency_code(),
                            'exchange_rate'  => "1 " .get_default_currency_code().' = '.get_amount($to_country->rate,$to_country->code),
                            'charges'   => getAmount( $total_charge, 2).' ' .get_default_currency_code(),
                            'payable'   =>  getAmount($payable,4).' ' .get_default_currency_code(),
                            'sending_country'   => @$form_country,
                            'receiving_country'   => @$to_country->country,
                            'receiver_name'  =>  @$receipient->firstname.' '.@$receipient->lastname,
                            'alias'  =>  ucwords(str_replace('-', ' ', @$receipient->alias)),
                            'transaction_type'  =>  @$transaction_type,
                            'receiver_get'   =>  getAmount($receiver_will_get,4).' ' .$to_country->code,
                            'status'  => "Pending",
                        ];
                        //sender notifications
                        $user->notify(new BankTransferMail($user,(object)$notifyData));
                }
            }
            Session::flash('success', [__('Remittance Money send successfully')]);
            return Response::success(['success' => [__('Remittance Money send successfully')]],['url' => route('user.remittance.index')]);
        }catch(Exception $e) {
            return Response::error([__("Something went wrong! Please try again.")]);
        }

    }
      //start transaction helpers
        //serder transaction
    public function insertSender($trx_id,$user,$userWallet,$send_amount,$receiver_will_get,$payable,$receipient,$form_country,$to_country,$transaction_type) {
            $trx_id = $trx_id;
            $authWallet = $userWallet;
            $afterCharge = ($authWallet->balance - $payable);
            $details =[
                'recipient_amount' => $receiver_will_get,
                'receiver' => $receipient,
                'form_country' => $form_country,
                'to_country' => $to_country,
                'remitance_type' => $transaction_type,
                'sender' => $user,
                'bank_account' => $receipient->account_number??'',
            ];
            if($transaction_type == Str::slug(GlobalConst::TRX_WALLET_TO_WALLET_TRANSFER)){
                $status = 1;

            }else{
                $status = 2;
            }
            DB::beginTransaction();
            try{
                $id = DB::table("transactions")->insertGetId([
                    'user_id'                       => $user->id,
                    'user_wallet_id'                => $authWallet->id,
                    'payment_gateway_currency_id'   => null,
                    'type'                          => PaymentGatewayConst::SENDREMITTANCE,
                    'trx_id'                        => $trx_id,
                    'request_amount'                => $send_amount,
                    'payable'                       => $payable,
                    'available_balance'             => $afterCharge,
                    'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::SENDREMITTANCE," ")) . " To " .$receipient->fullname,
                    'details'                       => json_encode($details),
                    'attribute'                      =>PaymentGatewayConst::SEND,
                    'status'                        => $status,
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
        public function insertSenderCharges($fixedCharge,$percent_charge, $total_charge, $send_amount,$user,$sender,$receipient) {
            DB::beginTransaction();
            try{
                DB::table('transaction_charges')->insert([
                    'transaction_id'    => $sender,
                    'percent_charge'    => $percent_charge,
                    'fixed_charge'      =>$fixedCharge,
                    'total_charge'      =>$total_charge,
                    'created_at'        => now(),
                ]);
                DB::commit();

                //notification
                $notification_content = [
                    'title'         =>__("Send Remittance"),
                    'message'       => __("Send Remittance Request to")." ".$receipient->fullname.' ' .$send_amount.' '.get_default_currency_code()." ".__("Successful"),
                    'image'         => get_image($user->image,'user-profile'),
                ];

                UserNotification::create([
                    'type'      => NotificationConst::SEND_REMITTANCE,
                    'user_id'  => $user->id,
                    'message'   => $notification_content,
                ]);

                //Push Notifications
                event(new UserNotificationEvent($notification_content,$user));
                send_push_notification(["user-".$user->id],[
                    'title'     => $notification_content['title'],
                    'body'      => $notification_content['message'],
                    'icon'      => $notification_content['image'],
                ]);

                //admin notification
                $notification_content['title'] = __("Send Remittance Request to").' ('.$receipient->fullname.')';
                AdminNotification::create([
                    'type'      => NotificationConst::SEND_REMITTANCE,
                    'admin_id'  => 1,
                    'message'   => $notification_content,
                ]);
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                throw new Exception(__("Something went wrong! Please try again."));
            }
        }
        //Receiver Transaction
        public function insertReceiver($trx_id,$user,$userWallet,$send_amount,$receiver_will_get,$payable,$receipient,$form_country,$to_country,$transaction_type,$receiver_user,$receiver_wallet) {

            $trx_id = $trx_id;
            $receiverWallet = $receiver_wallet;
            $recipient_amount = ($receiverWallet->balance + $receiver_will_get);
            $details =[
                'recipient_amount' => $receiver_will_get,
                'receiver' => $receipient,
                'form_country' => $form_country,
                'to_country' => $to_country,
                'remitance_type' => $transaction_type,
                'sender' => $user,
                'bank_account' => $receipient->account_number??'',
            ];
            DB::beginTransaction();
            try{
                $id = DB::table("transactions")->insertGetId([
                    'user_id'                       => $receiver_user,
                    'user_wallet_id'                => $receiverWallet->id,
                    'payment_gateway_currency_id'   => null,
                    'type'                          => PaymentGatewayConst::SENDREMITTANCE,
                    'trx_id'                        => $trx_id,
                    'request_amount'                => $send_amount,
                    'payable'                       => $payable,
                    'available_balance'             => $recipient_amount,
                    'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::RECEIVEREMITTANCE," ")) . " From " .$user->fullname,
                    'details'                       => json_encode($details),
                    'attribute'                      =>PaymentGatewayConst::RECEIVED,
                    'status'                        => true,
                    'created_at'                    => now(),
                ]);
                $this->updateReceiverWalletBalance($receiverWallet,$recipient_amount);

                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                throw new Exception(__("Something went wrong! Please try again."));
            }
            return $id;
        }
        public function updateReceiverWalletBalance($receiverWallet,$recipient_amount) {
            $receiverWallet->update([
                'balance'   => $recipient_amount,
            ]);
        }
        public function insertReceiverCharges( $fixedCharge,$percent_charge, $total_charge, $send_amount,$user,$receiverTrans,$receipient,$receiver_user) {
            DB::beginTransaction();
            try{
                DB::table('transaction_charges')->insert([
                    'transaction_id'    => $receiverTrans,
                    'percent_charge'    => $percent_charge,
                    'fixed_charge'      =>$fixedCharge,
                    'total_charge'      =>$total_charge,
                    'created_at'        => now(),
                ]);
                DB::commit();

                //notification
                $notification_content = [
                    'title'         =>__("Send Remittance"),
                    'message'       => __("Send Remittance From")." ".$user->fullname.' ' .$send_amount.' '.get_default_currency_code()." ".__('Successful'),
                    'image'         => get_image($receipient->image,'user-profile'),
                ];

                UserNotification::create([
                    'type'      => NotificationConst::SEND_REMITTANCE,
                    'user_id'  => $receiver_user,
                    'message'   => $notification_content,
                ]);

                 //Push Notifications
                 event(new UserNotificationEvent($notification_content,$user));
                 send_push_notification(["user-".$user->id],[
                     'title'     => $notification_content['title'],
                     'body'      => $notification_content['message'],
                     'icon'      => $notification_content['image'],
                 ]);

                 //admin notification
                 $notification_content['title'] = __('Send Remittance From').' ('.$user->fullname.')';
                 AdminNotification::create([
                     'type'      => NotificationConst::SEND_REMITTANCE,
                     'admin_id'  => 1,
                     'message'   => $notification_content,
                 ]);
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                throw new Exception(__("Something went wrong! Please try again."));
            }
        }
    //end transaction helpers
    public function getToken() {
        $data = request()->all();
        $in['receiver_country'] = $data['receiver_country'];
        $in['transacion_type'] = $data['transacion_type'];
        $in['recipient'] = $data['recipient'];
        $in['sender_amount'] = $data['sender_amount'];
        $in['receive_amount'] = $data['receive_amount'];
        Session::put('remittance_token',$in);
        return response()->json($data);

    }
    public function getRecipientByCountry(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
        if( $transacion_type != null || $transacion_type != ''){
            $data['recipient'] = Receipient::auth()->where('country', $receiver_country)->where('type',$transacion_type)->get();

        }else{
            $data['recipient'] = Receipient::auth()->where('country', $receiver_country)->get();
        }
        return response()->json($data);
    }
    public function getRecipientByTransType(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
          $data['recipient'] = Receipient::auth()->where('country', $receiver_country)->where('type',$transacion_type)->get();
        return response()->json($data);
    }
}

