<?php

namespace Increment\Finance\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Models\Ledger;
use Increment\Common\Image\Models\Image;
use Increment\Imarket\Cart\Models\Checkout;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class LedgerController extends APIController
{
    public $notificationSettingClass = 'App\Http\Controllers\NotificationSettingController';

    function __construct(){
      $this->model = new Ledger();
      if($this->checkAuthenticatedUser() == false){
        return $this->response();
      }
      $this->localization();
    }
    
    public function generateCode(){
      $code = 'led_'.substr(str_shuffle($this->codeSource), 0, 60);
      $codeExist = Ledger::where('code', '=', $code)->get();
      if(sizeof($codeExist) > 0){
        $this->generateCode();
      }else{
        return $code;
      }
    }

    public function summary(Request $request){
      $data = $request->all();
      $result = array();
      foreach ($this->currency as $key) {
        $sum = $this->getSum($data['account_id'], $data['account_code'], $key);
        $hold = $this->getPendingAmount($data['account_id'], $key);
        $currency = array(
          'currency'  => $key,
          'balance'   => floatval($sum - $hold),
          'on_hold'   => $sum
        );
        $result[] = $currency;
      }
      $this->response['data'] = $result;
      return $this->response();
    }

    public function getPendingAmount($accountId, $currency){
      $total = 0;
      
      if(env('REQUEST') == true){
        $total += app('App\Http\Controllers\RequestMoneyController')->getTotalActiveRequest($accountId, $currency);
      }

      return $total;
    }

    public function getSum($accountId, $accountCode, $currency){
      $total = Ledger::where('account_id', '=', $accountId)->where('account_code', '=', $accountCode)->where('currency', '=', $currency)->sum('amount');
      return $total;
    }

    public function addEntry($data){
      $amount = Checkout::select("total")->where("id", $data["checkout_id"])->get();
      $entry = array();
      $entry["payment_payload"] = $data["payment_payload"];
      $entry["payment_payload_value"] = $data["payment_payload_value"];
      $entry["code"] = $this->generateCode();
      $entry["account_id"] = $data["account_id"];
      $entry["account_code"] = $data["account_code"];
      $entry["description"] = $data["status"];
      $entry["currency"] = $data["currency"];
      $entry["amount"] = $amount[0]["total"];
      $this->model = new Ledger();
      $this->insertDB($entry);
      return $this->response();
    }

    public function transfer(Request $request){
      $data = $request->all();
      $amount = floatval($data['amount']);
      $this->response['error'] = null;
      // check account if exist
      // check if balance is sufficient
      // send otp on first request
      // verify otp on confirmation
      $myBalance = floatval($this->retrievePersonal($data['from_account_id'], $data['from_account_code'], $data['currency']));
      if($myBalance < $amount){
        $this->response['stage'] = 1;
        $this->response['error'] = 'You have insufficient balance. Your current balance is '.$data['currency'].' '.$myBalance.' balance.';
        return $this->response();
      }
      if($data['stage'] == 1){
        app($this->notificationSettingClass)->generateOtpById($data['from_account_id']);
        $this->response['data'] = true;
        $this->response['stage'] = 2;
        return $this->response();
      }
      if($data['stage'] == 2){
        $notification = app($this->notificationSettingClass)->getByAccountIdAndCode($data['from_account_id'], $data['otp']);

        if($notification == null){
          $this->response['error'] = 'Invalid Code, please try again!';
          return $this->response();
        }

        $entry = [];
        $entry[] = array(
          "payment_payload" => $data["payment_payload"],
          "payment_payload_value" => $data["payment_payload"],
          "payment_payload_value" => $data["payment_payload_value"],
          "code" => $this->generateCode(),
          "account_id" => $data["account_id"],
          "account_code" => $data["account_code"],
          "description" => $data["description"],
          "currency" => $data["currency"],
          "amount" => $amount,
          'created_at' => Carbon::now()
        );

        if($data['type'] == 'AUTOMATIC'){
          $entry[] = array(
            "payment_payload" => $data["payment_payload"],
            "payment_payload_value" => $data["payment_payload_value"],
            "code" => $this->generateCode(),
            "account_id" => $data["from_account_id"],
            "account_code" => $data["from_account_code"],
            "description" => $data['from_description'],
            "currency" => $data["currency"],
            "amount" => $amount * (-1),
            'created_at' => Carbon::now()
          );
        }

        // $this->model = new Ledger();
        
        // $this->insertDB($entry);
        $this->response['data'] = Ledger::insert($entry);

        if($this->response['data'] > 0){
          // send email here
          $this->response['stage'] = 3;
        }
        
        return $this->response();
      }
      return $this->response();
    }

    public function history(Request $request){
      $data = $request->all();
      $result = Ledger::select('code', 'account_code', 'amount', 'description', 'currency', 'payment_payload', 'created_at')
                ->where('account_id', '=', $data['account_id'])
                ->where('account_code', '=', $data['account_code'])
                ->offset(isset($data['offset']) ? $data['offset'] : 0)
                ->limit(isset($data['limit']) ? $data['limit'] : 5)
                ->orderBy('created_at', 'desc')
                ->get();
      $i = 0;
      foreach ($result as $key) {
        $result[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y H:i A');
        $i++;
      }

      $this->response['data'] = $result;
      return $this->response();
    }

    public function retrieve(Request $request){
      //grabs by code
      //TODO: add security check for admin to grab bulk data
      //returns only one because of code constraint
      $result = Ledger::select("ledgers.*", "merchants.account_id AS merchant_id", "merchants.name")
        ->where("ledgers.code",$request["code"])
        ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
        ->get();
      $result[0]["logo"] = Image::select()
      ->where("account_id", $result[0]["merchant_id"])
      ->get();
      return $result;      
    }

    public function retrieveForMerchant(Request $request){
      $result = Ledger::select("ledgers.id AS ledger", "ledgers.code AS ledgerc", "ledgers.created_at AS ledger_created", "ledgers.updated_at AS ledger_updated", "ledgers.deleted_at AS ledger_delete",
       "ledgers.*", "merchants.*", "cash_methods.created_at AS cash_methods_created", "cash_methods.updated_at AS cash_methods_updated", "cash_methods.deleted_at AS cash_methods_deleted")
      ->where("ledgers.account_code", $request["code"])
      ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
      ->leftJoin("cash_methods", "ledgers.payment_payload_value", "=", "cash_methods.code")
      ->limit($request['limit'])
      ->offset($request['offset'])
      ->get();
      return $result;
    }

    public function retrieveByID(Request $request){
      //retrieves ledger entry by ID and passes ledger and merchant info
        $result = Ledger::select("ledgers.id AS ledger", "ledgers.code AS ledgerc", "ledgers.created_at AS ledger_created", "ledgers.updated_at AS ledger_updated", "ledgers.*", "merchants.*")
        ->where("ledgers.id",$request["id"])
        ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
        ->get();
      return $result; 
    }

    public function retrievePersonal($accountId, $accountCode, $currency){
      $ledger = Ledger::where('account_id', '=', $accountId)->where('account_code', '=', $accountCode)->where('currency', '=', $currency)->sum('amount');
      $total = doubleval($ledger);
      return doubleval($total);
    }
    
    public function summaryLedger(Request $request){
      $data = $request->all();
      $ledger = DB::table("ledgers")
                ->where('account_id', '=', $data['account_id'])
                ->select('code', 'account_id', 'account_code', 'amount', 'description', 'currency', 'payment_payload', 'payment_payload_value', 'created_at')
                ->orderBy('created_at', 'desc')
                ->offset($data['offset'])
                ->limit($data['limit'])
                ->get();
      $i = 0;
      foreach ($ledger as $key) {
        $ledger[$i]->created_at_human = Carbon::createFromFormat('Y-m-d H:i:s', $ledger[$i]->created_at)->copy()->tz($this->response['timezone'])->format('F j, Y H:i A');
        $i++;
      }
      
      $this->response['data'] = $ledger;
      return $this->response();
    }

    public function transactionHistory(Request $request){
      $data = $request->all();
      $transactions = Ledger::select("ledgers.*")
      ->limit((isset($data['limit']) ? $data['limit'] : 0))
      ->groupBy('created_at', 'asc')
      ->orderBy('created_at', 'desc')
      ->get();
      return $transactions;
    }
}