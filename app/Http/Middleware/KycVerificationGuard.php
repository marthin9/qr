<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Providers\Admin\BasicSettingsProvider;
use App\Constants\GlobalConst;

class KycVerificationGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $basic_settings = BasicSettingsProvider::get();
        $guard = userGuard()['type'];
        if( $guard === 'USER'){
            $kyc_verification_status = $basic_settings->kyc_verification;
        }elseif($guard === 'AGENT'){
            $kyc_verification_status = $basic_settings->agent_kyc_verification;
        }elseif($guard === 'MERCHANT'){
            $kyc_verification_status = $basic_settings->merchant_kyc_verification;
        }
        if($kyc_verification_status) {
            $user = auth()->user();
            if($user->kyc_verified != GlobalConst::APPROVED) {
                $smg = __("Please verify your KYC information before any transactional action");
                if($user->kyc_verified == GlobalConst::PENDING) {
                    $smg = __("Your KYC information is pending. Please wait for admin confirmation.");
                }
                if(auth()->guard("web")->check()) {
                    return redirect()->route("user.profile.index")->with(['warning' => [$smg]]);
                }else if(auth()->guard("merchant")->check()) {
                    return redirect()->route("merchant.profile.index")->with(['warning' => [$smg]]);
                }else if(auth()->guard("agent")->check()) {
                    return redirect()->route("agent.profile.index")->with(['warning' => [$smg]]);
                }
            }
        }
        return $next($request);
    }
}
