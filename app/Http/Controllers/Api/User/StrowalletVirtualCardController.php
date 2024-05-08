<?php

namespace App\Http\Controllers\Api\User;

use Exception;
use App\Models\UserWallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\Admin\Currency;
use App\Models\VirtualCardApi;
use App\Models\UserNotification;
use App\Http\Helpers\Api\Helpers;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Models\StrowalletVirtualCard;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use App\Notifications\User\VirtualCard\CreateMail;
use App\Notifications\User\VirtualCard\Fund;
use Illuminate\Support\Facades\Validator;

class  StrowalletVirtualCardController extends Controller
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
        $user = auth()->user();
        $basic_settings = BasicSettings::first();
        $card_basic_info = [
            'card_back_details' => @$this->api->card_details,
            'card_bg' => get_image(@$this->api->image,'card-api'),
            'site_title' =>@$basic_settings->site_name,
            'site_logo' =>get_logo(@$basic_settings,'dark'),
            'site_fav' =>get_fav($basic_settings,'dark'),
        ];
        $myCards = StrowalletVirtualCard::where('user_id',$user->id)->latest()->limit($this->card_limit)->get()->map(function($data){
             $live_card_data = card_details($data->card_id,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url);

            $basic_settings = BasicSettings::first();
            $statusInfo = [
                "block" =>      0,
                "unblock" =>     1,
                ];
            return[
                'id' => $data->id,
                'name' => $data->name_on_card,
                'card_number'       => $data->card_number ?? '',
                'card_id'           => $data->card_id,
                'expiry'            => $data->expiry ?? '',
                'cvv'               => $data->cvv ?? '',
                'card_status'       => $data->card_status,
                'balance'           =>  getAmount(updateStroWalletCardBalance(auth()->user(),$data->card_id,$live_card_data),2),
                'card_back_details' => @$this->api->card_details,
                'site_title' =>@$basic_settings->site_name,
                'site_logo' =>get_logo(@$basic_settings,'dark'),
                'site_fav' =>get_fav($basic_settings,'dark'),
                'status' => $data->is_active,
                'is_default' => $data->is_default,
                'status_info' =>(object)$statusInfo ,
            ];
        });
        $cardCharge = TransactionSetting::where('slug','virtual_card')->where('status',1)->get()->map(function($data){

            return [
                'id' => $data->id,
                'slug' => $data->slug,
                'title' => $data->title,
                'fixed_charge' => getAmount($data->fixed_charge,2),
                'percent_charge' => getAmount($data->percent_charge,2),
                'min_limit' => getAmount($data->min_limit,2),
                'max_limit' => getAmount($data->max_limit,2),
            ];
        })->first();
        $transactions = Transaction::auth()->virtualCard()->latest()->get()->map(function($item){
            $statusInfo = [
                "success" =>      1,
                "pending" =>      2,
                "rejected" =>     3,
                ];

            return[
                'id' => $item->id,
                'trx' => $item->trx_id,
                'transactin_type' => "Virtual Card".'('. @$item->remark.')',
                'request_amount' => getAmount($item->request_amount,2).' '.get_default_currency_code() ,
                'payable' => getAmount($item->payable,2).' '.get_default_currency_code(),
                'total_charge' => getAmount($item->charge->total_charge,2).' '.get_default_currency_code(),
                'card_amount' => getAmount(@$item->details->card_info->balance,2).' '.get_default_currency_code(),
                'card_number' => $item->details->card_info->card_pan??$item->details->card_info->maskedPan??$item->details->card_info->card_number??"---- ---- ---- ----",
                'current_balance' => getAmount($item->available_balance,2).' '.get_default_currency_code(),
                'status' => $item->stringStatus->value ,
                'date_time' => $item->created_at ,
                'status_info' =>(object)$statusInfo ,

            ];
        });
        $userWallet = UserWallet::where('user_id',$user->id)->get()->map(function($data){
            return[
                'balance' => getAmount($data->balance,2),
                'currency' => get_default_currency_code(),
            ];
        })->first();
        $customer_email = $user->strowallet_customer->customerEmail??false;
        if($customer_email === false){
            $customer_card  = 0;
        }else{
            $customer_card  = StrowalletVirtualCard::where('customer_email',$customer_email)->count();
        }
        $data =[
            'base_curr' => get_default_currency_code(),
            'card_create_action' => $customer_card <  $this->card_limit ? true : false,
            'strowallet_customer_info' =>$user->strowallet_customer === null ? true : false,
            'card_basic_info' =>(object) $card_basic_info,
            'myCards'=> $myCards,
            'user'=>   $user,
            'userWallet'=>  (object)$userWallet,
            'cardCharge'=>(object)$cardCharge,
            'transactions'   => $transactions,
        ];
        $message =  ['success'=>[__('Virtual Card')]];
        return Helpers::success($data,$message);
    }
    //charge
    public function charges(){
        $cardCharge = TransactionSetting::where('slug','virtual_card')->where('status',1)->get()->map(function($data){
            return [
                'id' => $data->id,
                'slug' => $data->slug,
                'title' => $data->title,
                'fixed_charge' => getAmount($data->fixed_charge,2),
                'percent_charge' => getAmount($data->percent_charge,2),
                'min_limit' => getAmount($data->min_limit,2),
                'max_limit' => getAmount($data->max_limit,2),
            ];
        })->first();

        $data =[
            'base_curr' => get_default_currency_code(),
            'cardCharge'=>(object)$cardCharge
            ];
            $message =  ['success'=>[__('Fess & Charges')]];
            return Helpers::success($data,$message);

    }
    //card details
    public function cardDetails(){
        $validator = Validator::make(request()->all(), [
            'card_id'     => "required|string",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $card_id = request()->card_id;
        $user = auth()->user();
        $myCard = StrowalletVirtualCard::where('user_id',$user->id)->where('card_id',$card_id)->first();
        if(!$myCard){
            $error = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        }
        if($myCard->card_status == 'pending'){
            $card_details   = card_details($card_id,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url);

            if($card_details['status'] == false){
                $error = ['error'=>[__("Your Card Is Pending! Please Contact With Admin")]];
                return Helpers::error($error);
            }

            $myCard->user_id                   = authGuardApi()['user']->id;
            $myCard->card_status               = $card_details['data']['card_detail']['card_status'];
            $myCard->card_number               = $card_details['data']['card_detail']['card_number'];
            $myCard->last4                     = $card_details['data']['card_detail']['last4'];
            $myCard->cvv                       = $card_details['data']['card_detail']['cvv'];
            $myCard->expiry                    = $card_details['data']['card_detail']['expiry'];
            $myCard->save();
        }
        $myCards = StrowalletVirtualCard::where('card_id',$card_id)->where('user_id',$user->id)->get()->map(function($data){
            $basic_settings = BasicSettings::first();
            return[
                'id' => $data->id,
                'name' => $data->name_on_card,
                'card_id' => $data->card_id,
                'card_number'       => $data->card_number ?? '',
                'card_brand' => $data->card_brand,
                'card_user_id' => $data->card_user_id,
                'expiry' => $data->expiry,
                'cvv' => $data->cvv,
                'card_type' => ucwords($data->card_type),
                'city' => $data->user->strowallet_customer->city??"",
                'state' => $data->user->strowallet_customer->state??"",
                'zip_code' => $data->user->strowallet_customer->zipCode??"",
                'amount' => getAmount($data->balance,2),
                'card_back_details' => @$this->api->card_details,
                'card_bg' => get_image(@$this->api->image,'card-api'),
                'site_title' =>@$basic_settings->site_name,
                'site_logo' =>get_logo(@$basic_settings,'dark'),
                'status' => $data->is_active,
                'is_default' => $data->is_default,
            ];
        })->first();
        $data =[
            'base_curr' => get_default_currency_code(),
            'myCards'=> $myCards,
            ];
            $message =  ['success'=>[__('card Details')]];
            return Helpers::success($data,$message);
    }
    public function makeDefaultOrRemove(Request $request) {
        $validator = Validator::make($request->all(), [
            'card_id'     => "required|string",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();
        $user = auth()->user();
        $targetCard =  StrowalletVirtualCard::where('card_id',$validated['card_id'])->where('user_id',$user->id)->first();
        if(!$targetCard){
            $error = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        };
        $withOutTargetCards =  StrowalletVirtualCard::where('id','!=',$targetCard->id)->where('user_id',$user->id)->get();
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
            $message =  ['success'=>[__('Status Updated Successfully')]];
            return Helpers::onlysuccess($message);

        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    // card transactions
    public function cardTransaction() {
        $validator = Validator::make(request()->all(), [
            'card_id'     => "required|string",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $card_id = request()->card_id;
        $user = auth()->user();
        $card = StrowalletVirtualCard::where('user_id',$user->id)->where('card_id',$card_id)->first();
        if(!$card){
            $error = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        }

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

        $message = ['success' => [__('Virtual Card Transaction')]];
        return Helpers::success($result['response'], $message);
    }
    //card block
    public function cardBlock(Request $request){
        $validator = Validator::make($request->all(), [
            'card_id'     => "required|string",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $card_id = $request->card_id;
        $user = auth()->user();
        $status = 'freeze';
        $card = StrowalletVirtualCard::where('user_id',$user->id)->where('card_id',$card_id)->first();
        if(!$card){
            $error = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        }
        if($card->is_active == false){
            $error = ['error'=>[__('Sorry,This Card Is Already Freeze')]];
            return Helpers::error($error);
        }

        $client = new \GuzzleHttp\Client();
        $public_key     = $this->api->config->strowallet_public_key;
        $base_url       = $this->api->config->strowallet_url;

        $response = $client->request('POST', $base_url.'action/status/?action='.$status.'&card_id='.$card->card_id.'&public_key='.$public_key, [
        'headers' => [
            'accept' => 'application/json',
        ],
        ]);

        $result = $response->getBody();
        $data  = json_decode($result, true);

        if (isset($data)) {
            if ($data['status'] == 'true') {
                $card->is_active = 0;
                $card->save();
                $message =  ['success'=>[__('Card Freeze successfully')]];
                return Helpers::onlysuccess($message);
            }
        }

    }
    //unblock card
    public function cardUnBlock(Request $request){
        $validator  = Validator::make($request->all(), [
            'card_id'     => "required|string",
        ]);
        if($validator->fails()){
            $error  =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $card_id    = $request->card_id;
        $user       = auth()->user();
        $status     = 'unfreeze';
        $card       = StrowalletVirtualCard::where('user_id',$user->id)->where('card_id',$card_id)->first();
        if(!$card){
            $error  = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        }
        if($card->is_active == true){
            $error = ['error'=>[__('Sorry,This Card Is Already Unfreeze')]];
            return Helpers::error($error);
        }
        $client         = new \GuzzleHttp\Client();
        $public_key     = $this->api->config->strowallet_public_key;
        $base_url       = $this->api->config->strowallet_url;

        $response = $client->request('POST', $base_url.'action/status/?action='.$status.'&card_id='.$card->card_id.'&public_key='.$public_key, [
        'headers' => [
            'accept' => 'application/json',
        ],
        ]);

        $result = $response->getBody();
        $data  = json_decode($result, true);

        if (isset($data['status'])) {
            $card->is_active = 1;
            $card->save();
            $message =  ['success'=>[__('Card UnFreeze successfully')]];
            return Helpers::onlysuccess($message);
        }else{
            $error = ['error' => $data['message']];
            return Helpers::error(['error' => [$data['message']]]);
        }

    }

    //card buy
    public function cardBuy(Request $request){
        $user = auth()->user();
        if($user->strowallet_customer == null){
            $validator = Validator::make($request->all(), [
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
            ]);
        }else{
            $validator = Validator::make($request->all(), [
                'name_on_card'      => 'required|string|min:4|max:50',
                'card_amount'       => 'required|numeric|gt:0',
            ]);
        }
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $formData   = $request->all();


        $amount = $request->card_amount;
        $basic_setting = BasicSettings::first();
        $wallet = UserWallet::where('user_id',$user->id)->first();
        if(!$wallet){
            $error = ['error'=>[__('User wallet not found')]];
            return Helpers::error($error);
        }
        $cardCharge = TransactionSetting::where('slug','virtual_card')->where('status',1)->first();
        $baseCurrency = Currency::default();
        $rate = $baseCurrency->rate;
        if(!$baseCurrency){
            $error = ['error'=>[__('Default currency not found')]];
            return Helpers::error($error);
        }
        $minLimit =  $cardCharge->min_limit *  $rate;
        $maxLimit =  $cardCharge->max_limit *  $rate;
        if($amount < $minLimit || $amount > $maxLimit) {
            $error = ['error'=>[__("Please follow the transaction limit")]];
            return Helpers::error($error);
        }
        //charge calculations
        $fixedCharge = $cardCharge->fixed_charge *  $rate;
        $percent_charge = ($amount / 100) * $cardCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $amount;
        if($payable > $wallet->balance ){
            $error = ['error'=>[__('Sorry, insufficient balance')]];
            return Helpers::error($error);
        }

        if($user->strowallet_customer == null){
            $createCustomer     = stro_wallet_create_user($user,$formData,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url);

            if( $createCustomer['status'] == false){
                $error = ['error'=>[__("The customer doesn't create properly,Contact with owner")]];
                return Helpers::error($error);
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
            $error = ['error'=>[__("Sorry! You can not create more than")." ".$this->card_limit ." ".__("card using the same email address.")]];
            return Helpers::error($error);
        }


          // for live code
          $created_card = create_strowallet_virtual_card($user,$request->card_amount,$customer,$this->api->config->strowallet_public_key,$this->api->config->strowallet_url,$formData);
          if($created_card['status'] == false){
              $error = ['error'=>[$created_card['message'] .' ,'.__('Please Contact With Administration.')]];
              return Helpers::error($error);
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
            $this->insertBuyCardCharge( $fixedCharge,$percent_charge, $total_charge,$user,$sender,$strowallet_card->card_number??"---- ----- ---- ----");

            if($basic_setting->email_notification == true){
                $notifyDataSender = [
                    'trx_id'  => $trx_id,
                    'title'  => "Virtual Card (Buy Card)",
                    'request_amount'  => getAmount($amount,4).' '.get_default_currency_code(),
                    'payable'   =>  getAmount($payable,4).' ' .get_default_currency_code(),
                    'charges'   => getAmount( $total_charge, 2).' ' .get_default_currency_code(),
                    'card_amount'  => getAmount($amount, 2).' ' .get_default_currency_code(),
                    'card_pan'  =>   "---- ---- ---- ----",
                    'status'  => "Success",
                    ];
                $user->notify(new CreateMail($user,(object)$notifyDataSender));
                }
            $message =  ['success'=>[__('Virtual Card Buy Successfully')]];
            return Helpers::onlysuccess($message);

        }catch(Exception $e){

            $error =  ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
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
                'image'           => files_asset_path('profile-default'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::CARD_BUY,
                'user_id'   => $user->id,
                'message'   => $notification_content,
            ]);

             //admin notification
             $notification_content['title'] =__('Buy card successful').' '.$card_number.' Successful ('.$user->username.')';
             AdminNotification::create([
                 'type'      => NotificationConst::CARD_BUY,
                 'admin_id'  => 1,
                 'message'   => $notification_content,
             ]);
            DB::commit();
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
     * Card Fund
     */
    public function cardFundConfirm(Request $request){
        $validator = Validator::make($request->all(), [
            'card_id' => 'required',
            'fund_amount' => 'required|numeric|gt:0',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $basic_setting = BasicSettings::first();
        $user = auth()->user();
        $myCard =  StrowalletVirtualCard::where('user_id',$user->id)->where('card_id',$request->card_id)->first();

        if(!$myCard){
            $error = ['error'=>[__("Something is wrong in your card")]];
            return Helpers::error($error);
        }

        $amount = $request->fund_amount;
        $wallet = UserWallet::where('user_id',$user->id)->first();
        if(!$wallet){
            $error = ['error'=>[__('User wallet not found')]];
            return Helpers::error($error);
        }
        $cardCharge = TransactionSetting::where('slug','reload_card')->where('status',1)->first();
        $baseCurrency = Currency::default();
        $rate = $baseCurrency->rate;
        if(!$baseCurrency){
            $error = ['error'=>[__('Default currency not found')]];
            return Helpers::error($error);
        }
        $fixedCharge = $cardCharge->fixed_charge *  $rate;
        $percent_charge = ($amount / 100) * $cardCharge->percent_charge;
        $total_charge = $fixedCharge + $percent_charge;
        $payable = $total_charge + $amount;
        if($payable > $wallet->balance ){
            $error = ['error'=>[__('Sorry, insufficient balance')]];
            return Helpers::error($error);
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
            $message =  ['success'=>[__('Card Funded Successfully')]];
            return Helpers::onlysuccess($message);

        }else{

            $error = ['error'=>[@$decodedResult['message'].' ,'.__('Please Contact With Administration.')]];
            return Helpers::error($error);
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


}
