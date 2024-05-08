<?php
namespace App\Traits\PaymentGateway;

use App\Constants\NotificationConst;
use Exception;
use Stripe\Charge;
use Stripe\Customer;
use App\Traits\Transaction;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe as StripePackage;
use App\Constants\PaymentGatewayConst;
use App\Events\Merchant\NotificationEvent;
use App\Models\UserNotification as ModelsUserNotification;
use App\Notifications\PaymentLink\BuyerNotification;
use App\Notifications\PaymentLink\UserNotification;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Jenssegers\Agent\Agent;
use App\Events\User\NotificationEvent as UserNotificationEvent;
use App\Models\Admin\AdminNotification;
use App\Models\Merchants\MerchantNotification;
use Illuminate\Support\Facades\Auth;

trait StripeLinkPayment{

    use Transaction;

    public function stripeLinkInit($output = null, $credentials) {
        if(!$output) $output = $this->output;
        StripePackage::setApiKey($credentials->secret_key);
        $cents = round($output['charge_calculation']['requested_amount'], 2) * 100;
        try {
            if($output['transaction_type'] == PaymentGatewayConst::TYPEPAYLINK){
                $type = 'payment_link';
                $trx_id = generateTrxString('transactions', 'trx_id', 'PL-', 8);
            }
            // Customer Create
            $customer = Customer::create(array(
                "email"  => $output['email'],
                "name"   => $output['card_name'],
                "source" => $output['token'],
            ));

            // Charge Create
            $charge = Charge::create ([
                "amount" => $cents,
                "currency" => $output['charge_calculation']['sender_cur_code'],
                "customer" => $customer->id,
                "description" => $output[$type]['title'],
            ]);


            if ($charge['status'] == 'succeeded') {
                $this->createTransactionStripeLink($output,$trx_id);

            $buyer = [
                'email' => $output['email'],
                'name'  => $output['card_name'],
            ];
            $basic_settings = BasicSettingsProvider::get();
            if($basic_settings->email_notification == true){

                if($output['userType'] == "USER"){
                    $user = $output[$type]->user;
                }else if($output['userType'] == "MERCHANT"){
                    $user = $output[$type]->merchant;
                }
                $user->notify(new UserNotification($user, $output, $trx_id));
                Notification::route('mail', $buyer['email'])->notify(new BuyerNotification($buyer, $output, $trx_id));
            }

               return true;
            }
        } catch (\Exception $e) {
            throw new Exception(__("Something went wrong! Please try again."));
        }


    }

    public function createTransactionStripeLink($output, $trx_id) {
        $trx_id =  $trx_id;
        try {
            $inserted_id = $this->insertRecordStripeLink($output,$trx_id);
            $this->insertChargesStripeLink($inserted_id, $output);
            $this->insertDeviceStripe($output,$inserted_id);
            return true;
        } catch (\Exception $e) {
            throw new Exception(__("Something went wrong! Please try again."));
        }

    }
    public function insertRecordStripeLink($output, $trx_id) {
        $trx_id = $trx_id;
        $type = 'payment_link';
        DB::beginTransaction();
        try{
            if($output['userType'] == "USER"){
                $id = DB::table("transactions")->insertGetId([
                    'user_id'                     => $output['receiver_wallet']->user_id,
                    'user_wallet_id'              => $output['receiver_wallet']->id,
                    'payment_link_id'             => $output[$type]->id,
                    'payment_gateway_currency_id' => NULL,
                    'type'                        => $output['transaction_type'],
                    'trx_id'                      => $trx_id,
                    'request_amount'              => $output['charge_calculation']['requested_amount'],
                    'payable'                     => $output['charge_calculation']['payable'],
                    'available_balance'           => $output['receiver_wallet']->balance + $output['charge_calculation']['conversion_payable'],
                    'remark'                      => ucwords($output['transaction_type']." Transaction Successfully"),
                    'details'                     => json_encode($output),
                    'status'                      => true,
                    'attribute'                   => PaymentGatewayConst::RECEIVED,
                    'created_at'                  => now(),
                ]);
            }else if($output['userType'] == "MERCHANT"){
                $id = DB::table("transactions")->insertGetId([
                    'merchant_id'                 => $output['receiver_wallet']->merchant_id,
                    'merchant_wallet_id'          => $output['receiver_wallet']->id,
                    'payment_link_id'             => $output[$type]->id,
                    'payment_gateway_currency_id' => NULL,
                    'type'                        => $output['transaction_type'],
                    'trx_id'                      => $trx_id,
                    'request_amount'              => $output['charge_calculation']['requested_amount'],
                    'payable'                     => $output['charge_calculation']['payable'],
                    'available_balance'           => $output['receiver_wallet']->balance + $output['charge_calculation']['conversion_payable'],
                    'remark'                      => ucwords($output['transaction_type']." Transaction Successfully"),
                    'details'                     => json_encode($output),
                    'status'                      => true,
                    'attribute'                   => PaymentGatewayConst::RECEIVED,
                    'created_at'                  => now(),
                ]);
            }

            $this->updateWalletBalanceStripeLink($output);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    public function insertChargesStripeLink($id,$output) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $output['charge_calculation']['percent_charge'],
                'fixed_charge'      => $output['charge_calculation']['fixed_charge'],
                'total_charge'      => $output['charge_calculation']['total_charge'],
                'created_at'        => now(),
            ]);
            DB::commit();
            if($output['userType'] == "USER"){
                $this->notificationUser($output);
            }else if($output['userType'] == "MERCHANT"){
                $this->notificationMerchant($output);
            }
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    public function notificationUser($output){
        $user = $output['receiver_wallet']->user;
         //notification
         $notification_content = [
            'title'         => __("Payment From PayLink"),
            'message'       => __("Your Wallet")." (".$output['receiver_wallet']->currency->code.") ".__("balance  has been added").' '.$output['charge_calculation']['conversion_payable'].' '. $output['receiver_wallet']->currency->code,
            'time'          => Carbon::now()->diffForHumans(),
            'image'         => get_image($user->image,'user-profile'),
        ];

        ModelsUserNotification::create([
            'type'      => NotificationConst::PAY_LINK,
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
         $notification_content['title'] = __("Payment From PayLink").' '.$output['charge_calculation']['conversion_payable'].' '.$output['receiver_wallet']->currency->code.' '.__("Successful").' ('.$user->username.')';
        AdminNotification::create([
            'type'      => NotificationConst::PAY_LINK,
            'admin_id'  => 1,
            'message'   => $notification_content,
        ]);
    }
    public function notificationMerchant($output){
        $user = $output['receiver_wallet']->merchant;
         //notification
         $notification_content = [
            'title'         => __("Payment From PayLink"),
            'message'       => __("Your Wallet")." (".$output['receiver_wallet']->currency->code.") ".__("balance  has been added").' '.$output['charge_calculation']['conversion_payable'].' '. $output['receiver_wallet']->currency->code,
            'time'          => Carbon::now()->diffForHumans(),
            'image'         => get_image($user->image,'merchant-profile'),
        ];

        MerchantNotification::create([
            'type'      => NotificationConst::PAY_LINK,
            'merchant_id'  => $user->id,
            'message'   => $notification_content,
        ]);

         //Push Notifications
         event(new NotificationEvent($notification_content,$user));
         send_push_notification(["merchant-".$user->id],[
             'title'     => $notification_content['title'],
             'body'      => $notification_content['message'],
             'icon'      => $notification_content['image'],
         ]);

        //admin notification
         $notification_content['title'] = __("Payment From PayLink").' '.$output['charge_calculation']['conversion_payable'].' '.$output['receiver_wallet']->currency->code.' '.__("Successful").' ('.$user->username.')';
        AdminNotification::create([
            'type'      => NotificationConst::PAY_LINK,
            'admin_id'  => 1,
            'message'   => $notification_content,
        ]);
    }
    public function insertDeviceStripe($output,$id) {
        $client_ip = request()->ip() ?? false;
        $location = geoip()->getLocation($client_ip);
        $agent = new Agent();
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
    public function updateWalletBalanceStripeLink($output) {
        $update_amount = $output['receiver_wallet']->balance + $output['charge_calculation']['conversion_payable'];
        $output['receiver_wallet']->update([
            'balance'   => $update_amount,
        ]);
    }


}
