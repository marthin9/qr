<?php

namespace App\Http\Controllers\User;

use Exception;
use App\Models\UserWallet;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Models\PaymentLink;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\Currency;
use App\Models\Admin\GatewayAPi;
use App\Models\Admin\TransactionSetting;
use App\Models\Merchants\MerchantWallet;
use Illuminate\Support\Facades\Validator;
use App\Traits\PaymentGateway\StripeLinkPayment;

class PaymentLinkController extends Controller
{
    use StripeLinkPayment;
    /**
     * Payment link page show
     *
     * @method GET
     * @return Illuminate\Http\Request
     */
    public function index(){
        $page_title = __('Payment Links');
        $payment_links = PaymentLink::auth()->orderBy('id', 'desc')->paginate(12);
        return view('user.sections.payment-link.index', compact('page_title', 'payment_links'));
    }


    /**
     * Payment link create page show
     *
     * @method GET
     * @return Illuminate\Http\Request
     */
    public function create(){
        $page_title = __('Payment Link Create');
        try {
            $currency_data = getCurrencyList();
        } catch (\Exception $th) {
            return back()->with(['error' => [__('Unable to connect with API, Please Contact Support!!')]]);
        }

        return view('user.sections.payment-link.create', compact('page_title','currency_data'));
    }

    /**
     * Payment link store
     *
     * @param Illuminate\Http\Request $request
     * @method POST
     * @return Illuminate\Http\Request
     */
    public function store(Request $request){

        $token = generate_unique_string('payment_links', 'token', 60);

        if($request->type == PaymentGatewayConst::LINK_TYPE_PAY){
            $validator = Validator::make($request->all(), [
                'currency'        => 'required|string',
                'currency_symbol' => 'required|string',
                'country'         => 'required|string',
                'currency_name'   => 'required|string',
                'title'           => 'required|string|max:180',
                'type'            => 'required|string',
                'details'         => 'nullable|string',
                'limit'           => 'nullable',
                'min_amount'      => 'nullable|numeric|min:0.1',
                'max_amount'      => 'nullable|numeric|gt:min_amount',
                'image'           => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            ]);

            if($validator->stopOnFirstFailure()->fails()){
                return back()->withErrors($validator)->withInput();
            }

            $validated = $validator->validated();
            $validated = Arr::except($validated, ['image']);
            $validated['limit'] = $request->limit ? 1 : 2;
            $validated['token'] = $token;
            $validated['status'] = 1;
            $validated['user_id'] = Auth::id();

            try {
                $payment_link = PaymentLink::create($validated);

                if($request->hasFile('image')) {
                    try{
                        $image = get_files_from_fileholder($request,'image');
                        $upload_image = upload_files_from_path_dynamic($image,'payment-link-image');
                        $payment_link->update([
                            'image'  => $upload_image,
                        ]);
                    }catch(Exception $e) {
                        return back()->withErrors($validator)->withInput()->with(['error' => [__("Something went wrong! Please try again.")]]);
                    }
                }
            } catch (\Exception $th) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }

        }else{
            $validator = Validator::make($request->all(), [
                'sub_currency'    => 'required',
                'currency_symbol' => 'required',
                'currency_name'   => 'required',
                'country'         => 'required',
                'sub_title'       => 'required|max:180',
                'type'            => 'required',
                'price'           => 'nullable:numeric',
                'qty'             => 'nullable:integer',
            ]);


            if($validator->fails()){
                return back()->withErrors($validator)->withInput();
            }

            $validated = $validator->validated();
            $validated['currency'] = $validated['sub_currency'];
            $validated['title'] = $validated['sub_title'];
            $validated['token'] = $token;
            $validated['status'] = 1;
            $validated['user_id'] = Auth::id();

            $validated = Arr::except($validated, ['sub_currency','sub_title']);
            try {
                $payment_link = PaymentLink::create($validated);
            } catch (\Exception $th) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
        }


        return redirect()->route('user.payment-link.share', $payment_link->id)->with(['success' => [__('payment Link Created Successfully')]]);
    }


    /**
     * Payment link eidt page show
     *
     * @method GET
     * @return Illuminate\Http\Request
     */
    public function edit($id){
        $page_title = __('Payment Link Edit');

        try {
            $currency_data = getCurrencyList();
        } catch (\Exception $th) {
            return back()->with(['error' => [__('Unable to connect with API, Please Contact Support!!')]]);
        }

        $payment_link = PaymentLink::findOrFail($id);
        return view('user.sections.payment-link.edit', compact('page_title','currency_data','payment_link'));
    }


    /**
     * Payment link store
     *
     * @param Illuminate\Http\Request $request
     * @method POST
     * @return Illuminate\Http\Request
     */
    public function update(Request $request){

        $paymentLink = PaymentLink::find($request->target);

        if($request->type == PaymentGatewayConst::LINK_TYPE_PAY){
            $validator = Validator::make($request->all(), [
                'currency'        => 'required',
                'currency_symbol' => 'required',
                'currency_name'   => 'required',
                'title'           => 'required|max:180',
                'type'            => 'required',
                'details'         => 'nullable',
                'limit'           => 'nullable',
                'min_amount'      => 'nullable|min:0.1',
                'max_amount'      => 'nullable|gt:min_amount',
                'image'           => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            ]);

            if($validator->fails()){
                return back()->withErrors($validator)->withInput();
            }

            $validated = $validator->validated();

            if($paymentLink->type == PaymentGatewayConst::LINK_TYPE_SUB){
                $validated['price'] = NULL;
                $validated['qty'] = NULL;
            }


            $validated = Arr::except($validated, ['image']);
            $validated['limit'] = $request->limit ? 1 : 2;
            $validated['user_id'] = Auth::id();

            try {

                if($request->hasFile('image')) {
                    try{
                        $image = get_files_from_fileholder($request,'image');
                        $upload_image = upload_files_from_path_dynamic($image,'payment-link-image',$paymentLink->image);
                        $validated['image'] = $upload_image;
                    }catch(Exception $e) {
                        return back()->withErrors($validator)->withInput()->with(['error' => [__("Something went wrong! Please try again.")]]);
                    }
                }

                $paymentLink->update($validated);

            } catch (\Exception $th) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }

        }else{
            $validator = Validator::make($request->all(), [
                'sub_currency'    => 'required',
                'currency_symbol' => 'required',
                'currency_name'   => 'required',
                'sub_title'       => 'required|max:180',
                'type'            => 'required',
                'price'           => 'nullable',
                'qty'             => 'nullable',
            ]);

            $validated = $validator->validated();
            $validated['currency'] = $validated['sub_currency'];
            $validated['title'] = $validated['sub_title'];
            $validated['user_id'] = Auth::id();

            if($paymentLink->type == PaymentGatewayConst::LINK_TYPE_PAY){

                $validated['image'] = NULL;
                $validated['details'] = NULL;
                $validated['limit'] = 2;
                $validated['min_amount'] = NULL;
                $validated['max_amount'] = NULL;

                $image_link = get_files_path('payment-link-image') . '/' . $paymentLink->image;
                delete_file($image_link);
            }

            $validated = Arr::except($validated, ['sub_currency','sub_title']);
            try {
                $paymentLink->update($validated);
            } catch (\Exception $th) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
        }


        return redirect()->route('user.payment-link.share', $paymentLink->id)->with(['success' => [__('Payment Link Updated Successful')]]);
    }

    /**
     * Payment link store
     *
     * @param Illuminate\Http\Request $request
     * @method POST
     * @return Illuminate\Http\Request
     */
    public function status(Request $request){
        $validator = Validator::make($request->all(), [
            'target'        => 'required',
        ]);

        if($validator->fails()){
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();
        $paymentLink = PaymentLink::find($validated['target']);

        try {
            $status = $paymentLink->status == 1 ? 2 : 1;
            $paymentLink->update(['status' => $status]);

        } catch (\Exception $th) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return redirect()->route('user.payment-link.index')->with(['success' => [__('Payment Link Status Updated Successful')]]);
    }


    /**
     * Payment link eidt page show
     *
     * @method GET
     * @return Illuminate\Http\Request
     */
    public function share($id){
        $page_title = __('Link Share');
        $payment_link = PaymentLink::findOrFail($id);
        return view('user.sections.payment-link.share', compact('page_title','payment_link'));
    }

    /**
     * Payment Link Share
     *
     * @method GET
     * @return Illuminate\Http\Request
     */

    public function paymentLinkShare($token){
        $payment_link = PaymentLink::with('user','merchant')->where('status', 1)->where('token', $token)->first();
        if(empty($payment_link)){
            return redirect()->route('index')->with(['error' => [__('Invalid Payment Link')]]);
        }
        $credentials = GatewayAPi::first();
        if(empty($credentials)){
            return redirect()->route('index')->with(['error' => [__('Can Not Payment Now, Please Contact Support')]]);
        }
        $public_key = $credentials->public_key;

        $page_title = __('Payment Link');
        return view('frontend.paylink.share', compact('payment_link', 'page_title', 'public_key'));
    }

    /**
     * Payment Link Share
     *
     * @param @return Illuminate\Http\Request $request
     * @method POST
     * @return Illuminate\Http\Request
     */

    public function paymentLinkSubmit(Request $request){

        $validator = Validator::make($request->all(),[
            'target'     => 'required',
            'email'      => 'required|email',
            'card_name'  => 'required',
            'token'      => 'required',
            'last4_card' => 'required',
            'amount'     => 'required|gt:0',
        ]);

        if($validator->fails()){
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        $credentials = GatewayAPi::first();
        if(empty($credentials)){
            return back()->with(['error' => [__("Transaction Failed. The record didn't save properly. Please try again")]]);
        }

        $payment_link = PaymentLink::with('user','merchant')->find($validated['target']);
        if(!empty($payment_link->price)){
            $amount = $payment_link->price * $payment_link->qty;
            if($validated['amount'] != $amount){
                return back()->with(['error' => [__('Please Enter A Valid Amount')]]);
            }
        }else{
            if($payment_link->limit == 1){
                if($validated['amount'] < $payment_link->min_amount || $validated['amount'] > $payment_link->max_amount){
                    return back()->with(['error' => [__("Please follow the transaction limit")]]);
                }else{
                    $amount = $validated['amount'];
                }
            }else{
                $amount = $validated['amount'];
            }
        }
        $validated['payment_link'] = $payment_link;
        $receiver_currency = Currency::where('code', $validated['payment_link']->currency)->first();
        if(empty($receiver_currency)){
            return back()->with(['error' => [__('Receiver currency not found!')]]);
        }
        if($payment_link->user_id != null){
            $receiver_wallet = UserWallet::with('user','currency')->where('user_id', $payment_link->user_id)->first();
            $userType = "USER";
        }elseif($payment_link->merchant_id != null){
            $receiver_wallet = MerchantWallet::with('merchant','currency')->where('merchant_id', $payment_link->merchant_id)->first();
            $userType = "MERCHANT";
        }

        if(empty($receiver_wallet)){
            return back()->with(['error' => [__('Receiver wallet not found')]]);
        }

        $sender_currency = Currency::where('code', $payment_link->currency)->where('name', $payment_link->currency_name)->first();

        $validated['receiver_wallet'] = $receiver_wallet;
        $validated['sender_currency'] = $sender_currency;
        $validated['transaction_type'] = PaymentGatewayConst::TYPEPAYLINK;

        $payment_link_charge = TransactionSetting::where('slug', PaymentGatewayConst::paylink_slug())->where('status',1)->first();

        $fixedCharge        = $payment_link_charge->fixed_charge * $sender_currency->rate;
        $percent_charge     = ($amount / 100) * $payment_link_charge->percent_charge;
        $total_charge       = $fixedCharge + $percent_charge;
        $payable            = $amount - $total_charge;



        if($payable <= 0 ){
            return back()->with(['error' => [__('Transaction Failed, Please Contact With Support!')]]);
        }

        $conversion_charge  = conversionAmountCalculation($total_charge, $sender_currency->rate, $receiver_currency->rate);
        $conversion_payable = conversionAmountCalculation($payable, $sender_currency->rate ,$receiver_currency->rate);
        $exchange_rate      = conversionAmountCalculation(1, $receiver_currency->rate, $sender_currency->rate);
        $conversion_admin_charge = $total_charge / $sender_currency->rate;

        $charge_calculation = [
            'requested_amount'       => $amount,
            'request_amount_admin'   => $amount / $sender_currency->rate,
            'fixed_charge'           => $fixedCharge,
            'percent_charge'         => $percent_charge,
            'total_charge'           => $total_charge,
            'conversion_charge'      => $conversion_charge,
            'conversion_admin_charge'=> $conversion_admin_charge,
            'payable'                => $payable,
            'conversion_payable'     => $conversion_payable,
            'exchange_rate'          => $exchange_rate,
            'sender_cur_code'        => $payment_link->currency,
            'receiver_currency_code' => $receiver_currency->code,
            'base_currency_code'     => get_default_currency_code(),
        ];

        $validated['charge_calculation'] = $charge_calculation;
        $validated['userType'] = $userType??"";
       try {
            $this->stripeLinkInit($validated, $credentials);
            return redirect()->route('payment-link.transaction.success', $payment_link->token)->with(['success' => [__('Transaction Successful')]]);
       } catch (\Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
       }

    }


     /**
     * Transaction Success
     *
     * @method GET
     * @return Illuminate\Http\Request
     */

     public function transactionSuccess($token){
        $payment_link = PaymentLink::with('user')->where('token', $token)->first();
        $page_title = __('payment Success');
        return view('frontend.paylink.transaction-success', compact('payment_link', 'page_title'));
    }
}
