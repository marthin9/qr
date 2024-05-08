<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Http\Helpers\Api\Helpers;
use App\Models\Admin\BasicSettings;
use Exception;
use Illuminate\Http\Request;

class DeveloperApiController extends Controller
{
    public function index()
    {
        $merchant = auth()->user();
        $data = [
            'client_id' => $merchant->developerApi->client_id??'',
            'client_secret' =>$merchant->developerApi->client_secret??'',
            'mode' =>$merchant->developerApi->mode??'',
        ];
        $message = ['success' => [__('Merchant Developer Api Key')]];
        return Helpers::success($data, $message);
    }
    public function updateMode(Request $request) {
        $basic_setting = BasicSettings::first();
        $user = auth()->user();
        if($basic_setting->merchant_kyc_verification){
            if( $user->kyc_verified == 0){
                $error = ['error'=>[__('Please submit kyc information!')]];
                return Helpers::error($error);
            }elseif($user->kyc_verified == 2){
                $error = ['error'=>[__('Please wait before admin approves your kyc information')]];
                return Helpers::error($error);
            }elseif($user->kyc_verified == 3){
                $error = ['error'=>[__('Admin rejected your kyc information, Please re-submit again')]];
                return Helpers::error($error);
            }
        }
        $merchant_developer_api = auth()->user()->developerApi;
        if(!$merchant_developer_api) {
            $error = ['error'=>[__('Developer API not found!')]];
            return Helpers::error($error);
        }
        $update_mode = ($merchant_developer_api->mode == PaymentGatewayConst::ENV_SANDBOX) ? PaymentGatewayConst::ENV_PRODUCTION : PaymentGatewayConst::ENV_SANDBOX;

        try{
            $merchant_developer_api->update([
                'mode'      => $update_mode,
            ]);
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message = ['success'=>[__('Developer API mode updated successfully!')]];
        return Helpers::onlysuccess($message);
    }

}
