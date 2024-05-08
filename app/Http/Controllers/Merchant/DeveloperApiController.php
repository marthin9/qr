<?php

namespace App\Http\Controllers\Merchant;

use App\Constants\GlobalConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\BasicSettings;
use Exception;
use Illuminate\Http\Request;

class DeveloperApiController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $merchant = auth()->user();
        $page_title = __("API Credentials");
        return view('merchant.sections.api.index',compact('page_title'));
    }

    public function updateMode(Request $request) {
        $basic_setting = BasicSettings::first();
        $user = userGuard()['user'];
        if($basic_setting->merchant_kyc_verification){
            if( $user->kyc_verified == 0){
                return redirect()->route('merchant.profile.index')->with(['error' => [__('Please submit kyc information!')]]);
            }elseif($user->kyc_verified == 2){
                return redirect()->route('merchant.profile.index')->with(['error' => [__('Please wait before admin approves your kyc information')]]);
            }elseif($user->kyc_verified == 3){
                return redirect()->route('merchant.profile.index')->with(['error' => [__('Admin rejected your kyc information, Please re-submit again')]]);
            }
        }
        $merchant_developer_api = auth()->user()->developerApi;
        if(!$merchant_developer_api) return back()->with(['error' => [__('Developer API not found!')]]);

        $update_mode = ($merchant_developer_api->mode == PaymentGatewayConst::ENV_SANDBOX) ? PaymentGatewayConst::ENV_PRODUCTION : PaymentGatewayConst::ENV_SANDBOX;

        try{
            $merchant_developer_api->update([
                'mode'      => $update_mode,
            ]);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return back()->with(['success' => [__('Developer API mode updated successfully!')]]);
    }
}
