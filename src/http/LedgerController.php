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
    public $accountClass = 'Increment\Account\Http\AccountController';
    public $notificationClass = 'Increment\Common\Notification\Http\NotificationController';
    public $firebaseController = '\App\Http\Controllers\FirebaseController';
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

    public function dashboard(Request $request){
      $data = $request->all();
      $result = array();
      $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
      if($account == null){
        $this->response['error'] = 'Invalid Access';
        $this->response['data'] = null;
        return $this->response();
      }
      foreach ($this->currency as $key) {
        $sum = $this->getSum($account['id'], $account['code'], $key);
        $hold = $this->getPendingAmount($account['id'], $key);
        $currency = array(
          'currency'  => $key,
          'available_balance'   => floatval($sum - $hold),
          'current_balance'     => $sum,
          'balance'             => floatval($sum - $hold),
        );
        $result[] = $currency;
      }

      $history = Ledger::select('code', 'account_code', 'amount', 'description', 'currency', 'payment_payload', 'created_at', 'payment_payload_value')
        ->where('account_id', '=', $account['id'])
        ->where('account_code', '=', $account['code'])
        ->offset(0)
        ->limit(3)
        ->orderBy('created_at', 'desc')
        ->get();
      $i = 0;

      foreach ($history as $key) {
        $history[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $history[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
        $i++;
      }

      $this->response['data'] = array(
        'ledger' => $result,
        'history' => $history
      );
      return $this->response();
    }

    public function summary(Request $request){
      $data = $request->all();
      $result = array();
      foreach ($this->currency as $key) {
        $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
        if($account == null){
          $this->response['error'] = 'Invalid Access';
          $this->response['data'] = null;
          return $this->response();
        }else{
          $sum = $this->getSum($account['id'], $account['code'], $key);
          $hold = $this->getPendingAmount($account['id'], $key);
          $currency = array(
            'currency'  => $key,
            'available_balance'   => floatval($sum - $hold),
            'current_balance'     => $sum,
            'balance'             => floatval($sum - $hold),
          );
          $result[] = $currency;
        }
      }
      $this->response['data'] = $result;
      return $this->response();
    }

    public function getPendingAmount($accountId, $currency){
      $total = 0;

      if(env('REQUEST') == true){
        $total += app('App\Http\Controllers\RequestMoneyController')->getTotalActiveRequest($accountId, $currency);
      }

      if(env('DEPOSIT') == true){
        $total += app('Increment\Finance\Http\DepositController')->getTotalByParams($accountId, $currency);
      }

      if(env('WITHDRAWAL') == true){
        $total += app('Increment\Finance\Http\WithdrawalController')->getTotalByParams($accountId, $currency);
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

    public function verify($data){
      $result = Ledger::where('account_id', '=', $data['account_id'])
        ->where('account_code', '=', $data['account_code'])
        ->where('description', '=', $data['description'])
        ->where('amount', '=', $data['amount'])
        ->where('currency', '=', $data['currency'])
        ->where('payment_payload', '=', $data['payment_payload'])
        ->where('payment_payload_value', '=', $data['payment_payload_value'])
        ->orderBy('created_at', 'desc')
        ->limit(1)
        ->get();
      if(sizeof($result) > 0){
        $currentDate = Carbon::now();
        $createdAt = Carbon::createFromFormat('Y-m-d H:i:s', $result[0]['created_at']);

        $minutes = $currentDate->diffInMinutes($createdAt);
        if($minutes >= 30){
          return null;
        }else{
          return $result[0];
        }
      }else{
        return null;
      }
    }

    public function addNewEntry($data){
      $duplicate = $this->verify($data);
      if($duplicate){
        return array(
          'error' => 'Duplicate Entry',
          'data'  => null
        );
      }else{
        $amount = $data['amount'];
        $entry = array();
        $entry["payment_payload"] = $data["payment_payload"];
        $entry["payment_payload_value"] = $data["payment_payload_value"];
        $entry["code"] = $this->generateCode();
        $entry["account_id"] = $data["account_id"];
        $entry["account_code"] = $data["account_code"];
        $entry["description"] = $data["description"];
        $entry["currency"] = $data["currency"];
        $entry["amount"] = $amount;
        $this->model = new Ledger();
        $this->insertDB($entry);

        if($this->response['data'] > 0){
          // run jobs here
          $parameter = array(
            'from'    => $data['from'],
            'to'      => $data['account_id'],
            'payload' => $data["description"],
            'payload_value' => $data['request_id'],
            'route'   => 'ledger/'.$data["payment_payload_value"],
            'created_at'  => Carbon::now()
          );
          app($this->notificationClass)->createByParams($parameter);
        }
        return array(
          'data' => $this->response['data'],
          'error' => null
        );
      }
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
      $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
      if($account == null){
        $this->response['error'] = 'Invalid Access';
        $this->response['data'] = null;
        return $this->response();
      }else{
        $result = Ledger::select('code', 'account_code', 'amount', 'description', 'currency', 'payment_payload', 'created_at', 'payment_payload_value')
          ->where('account_id', '=', $account['id'])
          ->where('account_code', '=', $account['code'])
          ->offset(isset($data['offset']) ? $data['offset'] : 0)
          ->limit(isset($data['limit']) ? $data['limit'] : 5)
          ->orderBy('created_at', 'desc')
          ->get();
        $i = 0;

        foreach ($result as $key) {
          $result[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
          $i++;
        }

        $this->response['data'] = $result;
        return $this->response();
      }
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
        $ledger[$i]->created_at_human = Carbon::createFromFormat('Y-m-d H:i:s', $ledger[$i]->created_at)->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
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

    public function directTransfer(Request $request){
      $data = $request->all();

      $from = $data['from'];
      $to = $data['to'];
      $amount = floatval($data['amount']);
      $currency = $data['currency'];
      $notes = $data['notes'];

      $fromEmail = $from['email'];
      $fromCode = $from['code'];

      $toEmail = $to['email'];
      $toCode = $to['code'];

      if($fromEmail == null || $fromCode == null || $toEmail == null || $toCode == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Details';
        return $this->response();
      }

      if($amount == null || ($amount & $amount <= 0) || $currency == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Details';
        return $this->response();        
      }

      // from account details
      $fromAccount = $this->retriveAccountDetailsByCode($fromCode);

      if($fromAccount == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Sender Account was not found!';
        return $this->response();       
      }

      if($fromAccount != null && $fromAccount['email'] != $fromEmail){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Sender Account!';
        return $this->response();       
      }
      
      $fromBalance = $this->retrievePersonal($fromAccount['id'], $fromAccount['code'], $currency);
      
      if($fromBalance < $amount){
        $this->response['data'] = null;
        $this->response['error'] = 'Insufficient Balance!';
        return $this->response();  
      }


      // to account details
      $toAccount = $this->retriveAccountDetailsByCode($toCode);
      if($toAccount == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Receiver Account was not found!';
        return $this->response();       
      }

      if($toAccount != null && $toAccount['email'] != $toEmail){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Receiver Account!';
        return $this->response();       
      }

      $result = $this->addNewEntryDirectTransfer(array(
        "payment_payload" => 'direct_transfer',
        "payment_payload_value" => $toAccount['code'],
        "code" => $this->generateCode(),
        "account_id" => $fromAccount["id"],
        "account_code" => $fromAccount["code"],
        "description" => 'Direct Transfer'.($notes != null ? ':'.$notes : null),
        "currency" => $currency,
        "amount" => $amount * -1,
        "from"   => $toAccount['id']
      ));

      if($result['error'] != null){
        $this->response['error'] = $result['error'];
        $this->response['data'] = $result['data'];
        return $this->response();
      }

      $result = $this->addNewEntryDirectTransfer(array(
        "payment_payload" => 'direct_transfer',
        "payment_payload_value" => $fromAccount['code'],
        "code" => $this->generateCode(),
        "account_id" => $toAccount["id"],
        "account_code" => $toAccount["code"],
        "description" => 'Direct Transfer'.($notes != null ? ':'.$notes : null),
        "currency" => $currency,
        "amount" => $amount,
        "from"   => $fromAccount['id']
      ));

      if($result['error'] != null){
        $this->response['error'] = $result['error'];
        $this->response['data'] = $result['data'];
        return $this->response();
      }

      if($result['error'] == null){
        $this->response['error'] = null;
        $this->response['data'] = true;
        return $this->response();
      }
    }

    public function acceptPaymentConfirmation(Request $request){
      $data = $request->all();

      $from = $data['from_code'];
      $fromEmail = $data['from_email'];
      $to = $data['to_code'];
      $toEmail = $data['to_email'];
      $amount = floatval($data['amount']);
      $currency = $data['currency'];
      $notes = $data['notes'];

      if($from == null || $to == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Details';
        return $this->response();
      }

      if($amount == null || ($amount & $amount <= 0) || $currency == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Details';
        return $this->response();        
      }

      // from account details
      $fromAccount = $this->retriveAccountDetailsByCode($from);

      if($fromAccount == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Sender Account was not found!';
        return $this->response();       
      }

      if($fromAccount != null && $fromAccount['email'] != $fromEmail){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Sender Account!';
        return $this->response();       
      }
      
      $fromBalance = $this->retrievePersonal($fromAccount['id'], $fromAccount['code'], $currency);
      
      if($fromBalance < $amount){
        $this->response['data'] = null;
        $this->response['error'] = 'Insufficient Balance!';
        return $this->response();  
      }


      // to account details
      $toAccount = $this->retriveAccountDetailsByCode($toCode);
      if($toAccount == null){
        $this->response['data'] = null;
        $this->response['error'] = 'Receiver Account was not found!';
        return $this->response();       
      }

      if($toAccount != null && $toAccount['email'] != $toEmail){
        $this->response['data'] = null;
        $this->response['error'] = 'Invalid Receiver Account!';
        return $this->response();       
      }

      app($this->firebaseController)->sendLocal(
        array(
          'data' => array(
            'from'    => $fromAccount,
            'to'      => $toAccount,
            'amount'  => $amount,
            'currency' => $currency,
            'notes'   => $notes,
            'topic'   => 'payments-'.$fromAccount['id']
          ),
          'notification' => array(
            'title' => 'Payment Notification',
            'body'  => 'Accept payments from '.$toEmail,
            'imageUrl' => env('DOMAIN').'increment/v1/storage/logo/logo.png'
          ),
          'topic'   => env('TOPIC').'Payments-'.$fromAccount['id']
        )
      );
      $this->response['data'] = true;
      $this->response['error'] = null;
      return $this->response();
    }


    public function addNewEntryDirectTransfer($data){
      $result = $this->verify($data);
      if($result){
        return array(
          'error' => 'Duplicate Entry',
          'data'  => null
        );
      }else{
        $amount = $data['amount'];
        $entry = array();
        $entry["payment_payload"] = $data["payment_payload"];
        $entry["payment_payload_value"] = $data["payment_payload_value"];
        $entry["code"] = $this->generateCode();
        $entry["account_id"] = $data["account_id"];
        $entry["account_code"] = $data["account_code"];
        $entry["description"] = $data["description"];
        $entry["currency"] = $data["currency"];
        $entry["amount"] = $amount;
        $this->model = new Ledger();
        $this->insertDB($entry);

        if($this->response['data'] > 0){
          // run jobs here
          $parameter = array(
            'from'    => $data['from'],
            'to'      => $data['account_id'],
            'payload' => $data["description"],
            'payload_value' => $entry["code"],
            'route'   => 'ledger/'.$entry["code"],
            'created_at'  => Carbon::now()
          );
          app($this->notificationClass)->createByParams($parameter);
        }
        return array(
          'data' => $this->response['data'],
          'error' => null
        );
      }
    }
}