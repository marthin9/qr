<?php

namespace App\Http\Controllers\Admin;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Exports\MobileTopUpTrxExport;
use App\Http\Controllers\Controller;
use App\Http\Helpers\Response;
use App\Models\AgentNotification;
use App\Models\AgentWallet;
use App\Models\Merchants\MerchantNotification;
use App\Models\Merchants\MerchantWallet;
use App\Models\TopupCategory;
use App\Models\Transaction;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\User\MobileTopup\Approved;
use App\Notifications\User\MobileTopup\Rejected;
use App\Providers\Admin\BasicSettingsProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class SetupMobileTopupController extends Controller
{
    protected $basic_settings;

    public function __construct()
    {
            $this->basic_settings = BasicSettingsProvider::get();
    }
    //==============================================category start================================================
        public function topUpcategories(){
            $page_title = __("Mobile Topup Category");
            $allCategory = TopupCategory::orderByDesc('id')->paginate(10);
            return view('admin.sections.mobile-topups.category',compact(
                'page_title',
                'allCategory',
            ));
        }
        public function storeCategory(Request $request){

            $validator = Validator::make($request->all(),[
                'name'      => 'required|string|max:200|unique:topup_categories,name',
            ]);
            if($validator->fails()) {
                return back()->withErrors($validator)->withInput()->with('modal','category-add');
            }
            $validated = $validator->validate();
            $slugData = Str::slug($request->name);
            $makeUnique = TopupCategory::where('slug',  $slugData)->first();
            if($makeUnique){
                return back()->with(['error' => [ $request->name.' '.'Category Already Exists!']]);
            }
            $admin = Auth::user();

            $validated['admin_id']      = $admin->id;
            $validated['name']          = $request->name;
            $validated['slug']          = $slugData;
            try{
                TopupCategory::create($validated);
                return back()->with(['success' => [__("Category Saved Successfully!")]]);
            }catch(Exception $e) {
                return back()->withErrors($validator)->withInput()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
        }
        public function categoryUpdate(Request $request){
            $target = $request->target;
            $category = TopupCategory::where('id',$target)->first();
            $validator = Validator::make($request->all(),[
                'name'      => 'required|string|max:200',
            ]);
            if($validator->fails()) {
                return back()->withErrors($validator)->withInput()->with('modal','edit-category');
            }
            $validated = $validator->validate();

            $slugData = Str::slug($request->name);
            $makeUnique = TopupCategory::where('id',"!=",$category->id)->where('slug',  $slugData)->first();
            if($makeUnique){
                return back()->with(['error' => [__("Category Already Exists!")]]);
            }
            $admin = Auth::user();
            $validated['admin_id']      = $admin->id;
            $validated['name']          = $request->name;
            $validated['slug']          = $slugData;

            try{
                $category->fill($validated)->save();
                return back()->with(['success' => [__("Category Updated Successfully!")]]);
            }catch(Exception $e) {
                return back()->withErrors($validator)->withInput()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
        }

        public function categoryStatusUpdate(Request $request) {
            $validator = Validator::make($request->all(),[
                'status'                    => 'required|boolean',
                'data_target'               => 'required|string',
            ]);
            if ($validator->stopOnFirstFailure()->fails()) {
                $error = ['error' => $validator->errors()];
                return TopupCategory::error($error,null,400);
            }
            $validated = $validator->safe()->all();
            $category_id = $validated['data_target'];

            $category = TopupCategory::where('id',$category_id)->first();
            if(!$category) {
                $error = ['error' => [__("Category record not found in our system.")]];
                return Response::error($error,null,404);
            }

            try{
                $category->update([
                    'status' => ($validated['status'] == true) ? false : true,
                ]);
            }catch(Exception $e) {
                $error = ['error' => [__("Something went wrong! Please try again.")]];
                return Response::error($error,null,500);
            }

            $success = ['success' => [__("Category status updated successfully!")]];
            return Response::success($success,null,200);
        }
        public function categoryDelete(Request $request) {
            $validator = Validator::make($request->all(),[
                'target'        => 'required|string|exists:topup_categories,id',
            ]);
            $validated = $validator->validate();
            $category = TopupCategory::where("id",$validated['target'])->first();

            try{
                $category->delete();
            }catch(Exception $e) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }

            return back()->with(['success' => [__("Category deleted successfully!")]]);
        }
        public function categorySearch(Request $request) {
            $validator = Validator::make($request->all(),[
                'text'  => 'required|string',
            ]);

            if($validator->fails()) {
                $error = ['error' => $validator->errors()];
                return Response::error($error,null,400);
            }

            $validated = $validator->validate();

            $allCategory = TopupCategory::search($validated['text'])->select()->limit(10)->get();
            return view('admin.components.search.topup-category-search',compact(
                'allCategory',
            ));
        }
    //================================================category end=============================
     /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $page_title = __("All Logs");
        $transactions = Transaction::with(
          'user:id,firstname,lastname,email,username,full_mobile',
        )->where('type', PaymentGatewayConst::MOBILETOPUP)->latest()->paginate(20);
        return view('admin.sections.mobile-topups.index',compact(
            'page_title','transactions'
        ));
    }

    /**
     * Display All Pending Logs
     * @return view
     */
    public function pending() {
        $page_title =__( "Pending Logs");
        $transactions = Transaction::with(
          'user:id,firstname,lastname,email,username,full_mobile',
         )->where('type', PaymentGatewayConst::MOBILETOPUP)->where('status', 2)->latest()->paginate(20);
        return view('admin.sections.mobile-topups.index',compact(
            'page_title','transactions'
        ));
    }


    /**
     * Display All Complete Logs
     * @return view
     */
    public function complete() {
        $page_title = __("Complete Logs");
        $transactions = Transaction::with(
          'user:id,firstname,lastname,email,username,full_mobile',
         )->where('type', PaymentGatewayConst::MOBILETOPUP)->where('status', 1)->latest()->paginate(20);
        return view('admin.sections.mobile-topups.index',compact(
            'page_title','transactions'
        ));
    }


    /**
     * Display All Canceled Logs
     * @return view
     */
    public function canceled() {
        $page_title = "Canceled Logs";
        $transactions = Transaction::with(
            'user:id,firstname,lastname,email,username,full_mobile',
         )->where('type', PaymentGatewayConst::MOBILETOPUP)->where('status',4)->latest()->paginate(20);
        return view('admin.sections.mobile-topups.index',compact(
            'page_title','transactions'
        ));
    }
    public function details($id){

        $data = Transaction::where('id',$id)->with(
          'user:id,firstname,lastname,email,username,full_mobile',
        )->where('type',PaymentGatewayConst::MOBILETOPUP)->first();
        $pre_title = __("Mobile Topup details for");
        $page_title = $pre_title.'  '.$data->trx_id.' ('.@$data->details->topup_type_name.")";
        return view('admin.sections.mobile-topups.details', compact(
            'page_title',
            'data'
        ));
    }
    public function approved(Request $request){
        $validator = Validator::make($request->all(),[
            'id' => 'required|integer',
        ]);
        if($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }
        $data = Transaction::where('id',$request->id)->where('status',2)->where('type', PaymentGatewayConst::MOBILETOPUP)->first();
        $up['status'] = 1;
        try{
           $approved = $data->fill($up)->save();
           if( $approved){
            //notification
            $notification_content = [
                'title'         =>__( "Mobile Topup"),
                'message'       => "Your Mobile Topup request approved by admin " .getAmount($data->request_amount,2).' '.get_default_currency_code()." & Mobile Number is: ".@$data->details->mobile_number." successful.",
                'image'         => files_asset_path('profile-default'),
            ];

            if($data->user_id != null) {
                $notifyData = [
                    'trx_id'  => $data->trx_id,
                    'topup_type'  =>    @$data->details->topup_type_name,
                    'mobile_number'  => $data->details->mobile_number,
                    'request_amount'   => $data->request_amount,
                    'charges'   => $data->charge->total_charge,
                    'payable'  => $data->payable,
                    'current_balance'  => getAmount($data->available_balance, 4),
                    'status'  => "Success",
                  ];
                $user = $data->user;
                UserNotification::create([
                    'type'      => NotificationConst::MOBILE_TOPUP,
                    'user_id'  =>  $data->user_id,
                    'message'   => $notification_content,
                ]);
                DB::commit();
                if( $this->basic_settings->email_notification == true){
                    $user->notify(new Approved($user,(object)$notifyData));
                }

            }else if($data->agent_id != null) {
                $notifyData = [
                    'trx_id'  => $data->trx_id,
                    'topup_type'  =>    @$data->details->topup_type_name,
                    'mobile_number'  => $data->details->mobile_number,
                    'request_amount'   => $data->request_amount,
                    'charges'   => $data->charge->total_charge,
                    'payable'  => $data->payable,
                    'current_balance'  => getAmount($data->available_balance, 4),
                    'status'  => "Success",
                  ];
                $user = $data->agent;
                $returnWithProfit = ($user->wallet->balance +  $data->details->charges->agent_total_commission);
                $this->updateSenderWalletBalance($user->wallet,$returnWithProfit,$data);
                $this->agentProfitInsert($data->id,$user->wallet,(array)$data->details->charges);
                AgentNotification::create([
                    'type'      => NotificationConst::MOBILE_TOPUP,
                    'agent_id'  =>  $data->agent_id,
                    'message'   => $notification_content,
                ]);
                DB::commit();
                if( $this->basic_settings->agent_email_notification == true){
                    $user->notify(new Approved($user,(object)$notifyData));
                }

            }
           }

            return redirect()->back()->with(['success' => [__('Mobile topup request approved successfully')]]);
        }catch(Exception $e){
            return back()->with(['error' => [$e->getMessage()]]);
        }
    }
    public function rejected(Request $request){

        $validator = Validator::make($request->all(),[
            'id' => 'required|integer',
            'reject_reason' => 'required|string:max:200',
        ]);
        if($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }
        $data = Transaction::where('id',$request->id)->where('status',2)->where('type', PaymentGatewayConst::MOBILETOPUP)->first();
        try{
             //user wallet
             if($data->user_id != null) {
                $userWallet = UserWallet::where('user_id',$data->user_id)->first();
                $userWallet->balance +=  $data->payable;
                $userWallet->save();
            }else if($data->agent_id != null) {
                $userWallet = AgentWallet::where('agent_id',$data->agent_id)->first();
                $userWallet->balance +=  $data->payable;
                $userWallet->save();
            }
            $up['status'] = 4;
            $up['reject_reason'] = $request->reject_reason;
            $up['available_balance'] = $userWallet->balance;

            $rejected =  $data->fill($up)->save();
            if( $rejected){

                //user notifications
                $notification_content = [
                    'title'         => __("Mobile Topup"),
                    'message'       => "Your mobile topup request rejected by admin " .getAmount($data->request_amount,2).' '.get_default_currency_code()." & Mobile Number is: ".@$data->details->mobile_number,
                    'image'         => files_asset_path('profile-default'),
                ];

                if($data->user_id != null) {
                    $notifyData = [
                        'trx_id'  => $data->trx_id,
                        'topup_type'  =>    @$data->details->topup_type_name,
                        'mobile_number'  => $data->details->mobile_number,
                        'request_amount'   => $data->request_amount,
                        'charges'   => $data->charge->total_charge,
                        'payable'  => $data->payable,
                        'current_balance'  => getAmount($data->available_balance, 4),
                        'status'  => "Rejected",
                        'reason'  => $request->reject_reason,
                      ];
                    $user = $data->user;
                    UserNotification::create([
                        'type'      => NotificationConst::MOBILE_TOPUP,
                        'user_id'  =>  $data->user_id,
                        'message'   => $notification_content,
                    ]);
                    DB::commit();
                    if( $this->basic_settings->email_notification == true){
                    $user->notify(new Rejected($user,(object)$notifyData));
                    }

                }else if($data->agent_id != null) {
                    $notifyData = [
                        'trx_id'  => $data->trx_id,
                        'topup_type'  =>    @$data->details->topup_type_name,
                        'mobile_number'  => $data->details->mobile_number,
                        'request_amount'   => $data->request_amount,
                        'charges'   => $data->charge->total_charge,
                        'payable'  => $data->payable,
                        'current_balance'  => getAmount($data->available_balance, 4),
                        'status'  => "Rejected",
                        'reason'  => $request->reject_reason,
                      ];
                    $user = $data->user;
                    AgentNotification::create([
                        'type'      => NotificationConst::MOBILE_TOPUP,
                        'agent_id'  =>  $data->agent_id,
                        'message'   => $notification_content,
                    ]);
                    DB::commit();
                    if( $this->basic_settings->agent_email_notification == true){
                    $user->notify(new Rejected($user,(object)$notifyData));
                    }
                }
            }
            return redirect()->back()->with(['success' => [__("Mobile topup request rejected successfully")]]);
        }catch(Exception $e){
            return back()->with(['error' => [$e->getMessage()]]);
        }
    }
    public function agentProfitInsert($id,$authWallet,$charges) {
        DB::beginTransaction();
        try{
            DB::table('agent_profits')->insert([
                'agent_id'          => $authWallet->agent->id,
                'transaction_id'    => $id,
                'percent_charge'    => $charges['agent_percent_commission'],
                'fixed_charge'      => $charges['agent_fixed_commission'],
                'total_charge'      => $charges['agent_total_commission'],
                'created_at'        => now(),
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [$e->getMessage()]]);
        }
    }
    public function updateSenderWalletBalance($senderWallet,$afterCharge,$transaction) {
        $transaction->update([
            'available_balance'   => $afterCharge,
        ]);
        $senderWallet->update([
            'balance'   => $afterCharge,
        ]);
    }
    public function exportData(){
        $file_name = now()->format('Y-m-d_H:i:s') . "_Mobile_Top_Up_Logs".'.xlsx';
        return Excel::download(new MobileTopUpTrxExport, $file_name);
    }
}
