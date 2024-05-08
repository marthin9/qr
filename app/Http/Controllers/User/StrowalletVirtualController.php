<?php

namespace App\Http\Controllers\User;

use App\Notifications\User\VirtualCard\CreateMail;
use Exception;
use App\Models\UserWallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Http\Helpers\Response;
use App\Models\Admin\Currency;
use App\Models\VirtualCardApi;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Models\StrowalletVirtualCard;
use App\Constants\PaymentGatewayConst;
use App\Events\User\NotificationEvent;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use App\Notifications\User\VirtualCard\Fund;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class StrowalletVirtualController extends Controller
{
    protected $api;
    protected $card_limit;
    public function __construct()
    {
        $cardApi = VirtualCardApi::first();
        $this->api =  $cardApi;
        $this->card_limit =  $cardApi->card_limit;

    }
    public function index()
    {
        $page_title     = __("Virtual Card");
        $myCards = StrowalletVirtualCard::where('user_id', auth()->user()->id)->latest()->limit($this->card_limit)->get();
        $user           = auth()->user();
        $customer_email = $user->strowallet_customer->customerEmail??false;
        if($customer_email === false){
            $customer_card  = 0;
        }else{
            $customer_card  = StrowalletVirtualCard::where('customer_email',$customer_email)->count();
        }
        $cardCharge     = TransactionSetting::where('slug','virtual_card')->where('status',1)->first();
        $cardReloadCharge = TransactionSetting::where('slug','reload_card')->where('status',1)->first();
        $transactions   = Transaction::auth()->virtualCard()->latest()->take(5)->get();
        $cardApi = $this->api;
        return view('user.sections.virtual-card-strowallet.index',compact(
            'page_title',
            'cardApi',
            'myCards',
            'transactions',
            'cardCharge',
            'customer_card',
            'cardReloadCharge'

        ));
    }
    /**
     * Method for card details
     * @param $card_id
     * @param \Illuminate\Http\Request $request
     */
    public function cardDetails($card_id){
        $page_title = __("Card Details");
        $myCard = StrowalletVirtualCard::where('card_id',$card_id)->first();
        if(!$myCard) return back()->with(['error' => [__("Something is wrong in your card")]]);
        if($myCard->card_status == 'pending'){
            $card_details   = card_details($card_id,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url);

            if($card_details['status'] == false){
                return back()->with(['error' => [__("Your Card Is Pending! Please Contact With Admin")]]);
            }

            $myCard->user_id                   = Auth::user()->id;
            $myCard->card_status               = $card_details['data']['card_detail']['card_status'];
            $myCard->card_number               = $card_details['data']['card_detail']['card_number'];
            $myCard->last4                     = $card_details['data']['card_detail']['last4'];
            $myCard->cvv                       = $card_details['data']['card_detail']['cvv'];
            $myCard->expiry                    = $card_details['data']['card_detail']['expiry'];
            $myCard->save();
        }
        $cardApi = $this->api;
        return view('user.sections.virtual-card-strowallet.details',compact(
            'page_title',
            'myCard',
            'cardApi'
        ));
    }
    /**
     * Method for strowallet card buy
     */
    public function cardBuy(Request $request){
        $user = auth()->user();
        if ($user->strowallet_customer == null) {
            $request->validate([
                'card_amount'       => 'required|numeric|gt:0',
                'name_on_card'      => 'required|string|min:4|max:50',
                'first_name'        => ['required', 'string', 'regex:/^[^0-9\W]+$/'],
                'last_name'         => ['required', 'string', 'regex:/^[^0-9\W]+$/'],
                'house_number'      => 'required|string',
                'customer_email'    => 'required|string',
                'phone'             => 'required|string',
                'date_of_birth'     => 'required|string',
                'line1'             => 'required|string',
                'zip_code'          => 'required|string',
            ], [
                'first_name.regex'  => 'The Frist Name field should only contain letters and cannot start with a number or special character.',
                'last_name.regex'   => 'The Lasrt Name field should only contain letters and cannot start with a number or special character.',
            ]);
        } else {
            $request->validate([
                'card_amount'       => 'required|numeric|gt:0',
                'name_on_card'      => 'required|string|min:4|max:50',
            ]);
        }

        $formData   = $request->all();

        $amount = $request->card_amount;
        $basic_setting = BasicSettings::first();
        $wallet = UserWallet::where('user_id',$user->id)->first();
        if(!$wallet){
            return back()->with(['error' => [__('User wallet not found')]]);
        }
        $cardCharge = TransactionSetting::where('slug','virtual_card')->where('status',1)->first();
        $baseCurrency = Currency::default();
        $rate = $baseCurrency->rate;
        if(!$baseCurrency){
            return back()->with(['error' => [__('Default currency not found')]]);
        }
        $minLimit =  $cardCharge->min_limit *  $rate;
        $maxLimit =  $cardCharge->max_limit *  $rate;
        if($amount < $minLimit || $amount > $maxLimit) {
            return back()->with(['error' => [__("Please follow the transaction limit")]]);
        }
        //charge calculations
        $fixedCharge = $cardCharge->fixed_charge *  $rate;
        $percent_charge = ($amount / 100) * $cardCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $amount;
        if($payable > $wallet->balance ){
            return back()->with(['error' => [__('Sorry, insufficient balance')]]);
        }
        if($user->strowallet_customer == null){
            $createCustomer     = stro_wallet_create_user($user,$formData,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url);
            if( $createCustomer['status'] == false){
                return back()->with(['error' => [__("The customer doesn't create properly,Contact with owner")]]);
            }
            $user->strowallet_customer =   (object)$createCustomer['data'];
            $user->save();
            $customer = $user->strowallet_customer;
        }else{
            $customer = $user->strowallet_customer;
        }

        $customer_email = $user->strowallet_customer->customerEmail??false;
        if($customer_email === false){
            $customer_card  = 0;
        }else{
            $customer_card  = StrowalletVirtualCard::where('customer_email',$customer_email)->count();
        }

        if($customer_card >= $this->card_limit){
            return back()->with(['error' => [__("Sorry! You can not create more than")." ".$this->card_limit ." ".__("card using the same email address.")]]);
        }
        // for live code
        $created_card = create_strowallet_virtual_card($user,$request->card_amount,$customer,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url,$formData);

        if($created_card['status'] == false){
            return back()->with(['error' => [$created_card['message']]]);
        }

        $strowallet_card                            = new StrowalletVirtualCard();
        $strowallet_card->user_id                   = $user->id;
        $strowallet_card->name_on_card              = $created_card['data']['name_on_card'];
        $strowallet_card->card_id                   = $created_card['data']['card_id'];
        $strowallet_card->card_created_date         = $created_card['data']['card_created_date'];
        $strowallet_card->card_type                 = $created_card['data']['card_type'];
        $strowallet_card->card_brand                = $customer->card_brand;
        $strowallet_card->card_user_id              = $created_card['data']['card_user_id'];
        $strowallet_card->reference                 = $created_card['data']['reference'];
        $strowallet_card->card_status               = $created_card['data']['card_status'];
        $strowallet_card->customer_id               = $created_card['data']['customer_id'];
        $strowallet_card->customer_email            = $request->customer_email;
        $strowallet_card->balance                   = $amount;
        $strowallet_card->save();

        $trx_id =  'CB'.getTrxNum();
        try{
            $sender = $this->insertCardBuy( $trx_id,$user,$wallet,$amount, $strowallet_card ,$payable);
            $this->insertBuyCardCharge( $fixedCharge,$percent_charge, $total_charge,$user,$sender,$strowallet_card->card_number);
            if($basic_setting->email_notification == true){
                $notifyDataSender = [
                    'trx_id'  => $trx_id,
                    'title'  => "Virtual Card (Buy Card)",
                    'request_amount'  => getAmount($amount,4).' '.get_default_currency_code(),
                    'payable'   =>  getAmount($payable,4).' ' .get_default_currency_code(),
                    'charges'   => getAmount( $total_charge, 2).' ' .get_default_currency_code(),
                    'card_amount'  => getAmount($amount, 2).' ' .get_default_currency_code(),
                    'card_pan'  =>  "---- ----- ---- ----",
                    'status'  =>  $strowallet_card->card_status??"",
                    ];
                $user->notify(new CreateMail($user,(object)$notifyDataSender));
            }

            return redirect()->route("user.strowallet.virtual.card.index")->with(['success' => [__('Virtual Card Buy Successfully')]]);
        }catch(Exception $e){
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }



    }
    public function insertCardBuy( $trx_id,$user,$wallet,$amount, $strowallet_card ,$payable) {
        $trx_id = $trx_id;
        $authWallet = $wallet;
        $afterCharge = ($authWallet->balance - $payable);
        $details =[
            'card_info' =>   $strowallet_card??''
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                       => $user->id,
                'user_wallet_id'                => $authWallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::VIRTUALCARD,
                'trx_id'                        => $trx_id,
                'request_amount'                => $amount,
                'payable'                       => $payable,
                'available_balance'             => $afterCharge,
                'remark'                        => PaymentGatewayConst::CARDBUY,
                'details'                       => json_encode($details),
                'attribute'                      =>PaymentGatewayConst::RECEIVED,
                'status'                        => true,
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
    public function insertBuyCardCharge($fixedCharge,$percent_charge, $total_charge,$user,$id,$card_number) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $percent_charge,
                'fixed_charge'      => $fixedCharge,
                'total_charge'      => $total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();

            //notification
            $notification_content = [
                'title'         =>__('buy Card'),
                'message'       => __('Buy card successful')." ".$card_number,
                'image'         => files_asset_path('profile-default'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::CARD_BUY,
                'user_id'   => $user->id,
                'message'   => $notification_content,
            ]);

             //Push Notifications
             event(new NotificationEvent($notification_content,$user));
             send_push_notification(["user-".$user->id],[
                 'title'     => $notification_content['title'],
                 'body'      => $notification_content['message'],
                 'icon'      => $notification_content['image'],
             ]);

            //admin notification
            $notification_content['title'] =__('Buy card successful').' '.$card_number.' Successful ('.$user->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::CARD_BUY,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    //update user balance
    public function updateSenderWalletBalance($authWallet,$afterCharge) {
        $authWallet->update([
            'balance'   => $afterCharge,
        ]);
    }
    /**
     * card freeze unfreeze
     */
    public function cardBlockUnBlock(Request $request) {

        $validator = Validator::make($request->all(),[
            'status'                    => 'required|boolean',
            'data_target'               => 'required|string',
        ]);
        if ($validator->stopOnFirstFailure()->fails()) {
            $error = ['error' => $validator->errors()];
            return Response::error($error,null,400);
        }
        $validated = $validator->safe()->all();
        if($request->status == 1){

            $card   = StrowalletVirtualCard::where('id',$request->data_target)->where('is_active',1)->first();
            $client = new \GuzzleHttp\Client();
            $public_key     = $this->api->config->strowallet_public_key;
            $base_url       = $this->api->config->strowallet_url;

            $response = $client->request('POST', $base_url.'action/status/?action=freeze&card_id='.$card->card_id.'&public_key='.$public_key, [
            'headers' => [
                'accept' => 'application/json',
            ],
            ]);

            $result = $response->getBody();
            $data  = json_decode($result, true);

            if( isset($data['status']) ){
                $card->is_active = 0;
                $card->save();
                $success = ['success' => [__('Card Freeze successfully')]];
                return Response::success($success,null,200);
            }else{
                $error = ['error' =>  [$data['message']]];
                return Response::error($error,null,400);
            }
        }else{

            $card   = StrowalletVirtualCard::where('id',$request->data_target)->where('is_active',0)->first();
            $client = new \GuzzleHttp\Client();
            $public_key     = $this->api->config->strowallet_public_key;
            $base_url       = $this->api->config->strowallet_url;

            $response = $client->request('POST', $base_url.'action/status/?action=unfreeze&card_id='.$card->card_id.'&public_key='.$public_key, [
                'headers' => [
                    'accept' => 'application/json',
                ],
            ]);
            $result = $response->getBody();
            $data  = json_decode($result, true);
            if(isset($data['status'])){
                $card->is_active = 1;
                $card->save();
                $success = ['success' => [__('Card UnFreeze successfully')]];
                return Response::success($success,null,200);
            }else{
                $error = ['error' =>  [$data['message']]];
                return Response::error($error,null,400);
            }
        }

    }
    public function makeDefaultOrRemove(Request $request) {
        $validated = Validator::make($request->all(),[
            'target'        => "required|numeric",
        ])->validate();
        $user = auth()->user();
        $targetCard =  StrowalletVirtualCard::where('id',$validated['target'])->where('user_id',$user->id)->first();
        $withOutTargetCards =  StrowalletVirtualCard::where('id','!=',$validated['target'])->where('user_id',$user->id)->get();

        try{
            $targetCard->update([
                'is_default'         => $targetCard->is_default ? 0 : 1,
            ]);
            if(isset(  $withOutTargetCards)){
                foreach(  $withOutTargetCards as $card){
                    $card->is_default = false;
                    $card->save();
                }
            }

        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return back()->with(['success' => [__('Status Updated Successfully!')]]);
    }
    /**
     * Card Fund
     */
    public function cardFundConfirm(Request $request){
        $request->validate([
            'id' => 'required|integer',
            'fund_amount' => 'required|numeric|gt:0',
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
        $myCard =  StrowalletVirtualCard::where('user_id',$user->id)->where('id',$request->id)->first();
        if(!$myCard){
            return back()->with(['error' => [__("Something is wrong in your card")]]);
        }

        $amount = $request->fund_amount;
        $wallet = UserWallet::where('user_id',$user->id)->first();
        if(!$wallet){
            return back()->with(['error' => [__('User wallet not found')]]);
        }
        $cardCharge = TransactionSetting::where('slug','reload_card')->where('status',1)->first();
        $baseCurrency = Currency::default();
        $rate = $baseCurrency->rate;
        if(!$baseCurrency){
            return back()->with(['error' => [__('Default currency not found')]]);
        }
        $fixedCharge = $cardCharge->fixed_charge *  $rate;
        $percent_charge = ($amount / 100) * $cardCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $amount;
        if($payable > $wallet->balance ){
            return back()->with(['error' => [__('Sorry, insufficient balance')]]);
        }

        $public_key     = $this->api->config->strowallet_public_key;
        $base_url       = $this->api->config->strowallet_url;

        $client = new \GuzzleHttp\Client();

        $response               = $client->request('POST', $base_url.'fund-card/', [
            'headers'           => [
                'accept'        => 'application/json',
            ],
            'form_params'       => [
                'card_id'       => $myCard->card_id,
                'amount'        => $amount,
                'public_key'    => $public_key,
            ],
        ]);

        $result         = $response->getBody();
        $decodedResult  = json_decode($result, true);

        if(!empty($decodedResult['success'])  && $decodedResult['success'] == "success"){
            //added fund amount to card
            $myCard->balance += $amount;
            $myCard->save();
            $trx_id = 'CF'.getTrxNum();
            $sender = $this->insertCardFund( $trx_id,$user,$wallet,$amount, $myCard ,$payable);
            $this->insertFundCardCharge( $fixedCharge,$percent_charge, $total_charge,$user,$sender,$myCard->card_number,$amount);
            if($basic_setting->email_notification == true){
                $notifyDataSender = [
                    'trx_id'  => $trx_id,
                    'title'  => "Virtual Card (Fund Amount)",
                    'request_amount'  => getAmount($amount,4).' '.get_default_currency_code(),
                    'payable'   =>  getAmount($payable,4).' ' .get_default_currency_code(),
                    'charges'   => getAmount( $total_charge,2).' ' .get_default_currency_code(),
                    'card_amount'  => getAmount($myCard->balance,2).' ' .get_default_currency_code(),
                    'card_pan'  =>    $myCard->card_number,
                    'status'  => "Success",
                  ];
                $user->notify(new Fund($user,(object)$notifyDataSender));
            }
            return redirect()->back()->with(['success' => [__('Card Funded Successfully')]]);

        }else{
            return redirect()->back()->with(['error' => [@$decodedResult['message'].' ,'.__('Please Contact With Administration.')]]);
        }

    }
    //card fund helper
    public function insertCardFund( $trx_id,$user,$wallet,$amount, $myCard ,$payable) {
        $trx_id = $trx_id;
        $authWallet = $wallet;
        $afterCharge = ($authWallet->balance - $payable);
        $details =[
            'card_info' =>   $myCard??''
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                       => $user->id,
                'user_wallet_id'                => $authWallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::VIRTUALCARD,
                'trx_id'                        => $trx_id,
                'request_amount'                => $amount,
                'payable'                       => $payable,
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(PaymentGatewayConst::CARDFUND),
                'details'                       => json_encode($details),
                'attribute'                      =>PaymentGatewayConst::RECEIVED,
                'status'                        => true,
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
    public function insertFundCardCharge($fixedCharge,$percent_charge, $total_charge,$user,$id,$card_number,$amount) {
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
                'title'         =>__("Card Fund"),
                'message'       => __("Card fund successful card")." : ".$card_number.' '.getAmount($amount,2).' '.get_default_currency_code(),
                'image'         => files_asset_path('profile-default'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::CARD_FUND,
                'user_id'  => $user->id,
                'message'   => $notification_content,
            ]);

            //Push Notifications
            event(new NotificationEvent($notification_content,$user));
            send_push_notification(["user-".$user->id],[
                'title'     => $notification_content['title'],
                'body'      => $notification_content['message'],
                'icon'      => $notification_content['image'],
            ]);

           //admin notification
           $notification_content['title'] =__("Card fund successful card")." : ".$card_number.' '.getAmount($amount,2).' '.get_default_currency_code().'('.$user->username.')';
           AdminNotification::create([
               'type'      => NotificationConst::CARD_FUND,
               'admin_id'  => 1,
               'message'   => $notification_content,
           ]);
           DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    /**
     * Transactions
     */
    public function cardTransaction($card_id) {
        $user = auth()->user();
        $card = StrowalletVirtualCard::where('user_id',$user->id)->where('card_id', $card_id)->first();
        $page_title = __("Virtual Card Transaction");
        $id = $card->card_id;
        $emptyMessage  = 'No Transaction Found!';
        $start_date = date("Y-m-d", strtotime( date( "Y-m-d", strtotime( date("Y-m-d") ) ) . "-12 month" ) );
        $end_date = date('Y-m-d');
        $curl = curl_init();
        $public_key     = $this->api->config->strowallet_public_key;
        $base_url       = $this->api->config->strowallet_url;

        curl_setopt_array($curl, [
        CURLOPT_URL => $base_url . "card-transactions/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
            'public_key' => $public_key,
            'card_id' => $card->card_id,
        ]),
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "content-type: application/json"
        ],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        $result  = json_decode($response, true);

        if( $result['success'] == true ){
            $data =[
                'status'        => true,
                'message'       => "Card Details Retrieved Successfully.",
                'data'          => $result['response'],
            ];
        }else{
            $data =[
                'status'        => false,
                'message'       => $result['message'] ?? 'Something is wrong! Contact With Admin',
                'data'          => null,
            ];
        }

        return view('user.sections.virtual-card-strowallet.trx',compact('page_title','card','data'));


    }

}
