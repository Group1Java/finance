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
  public $payloadClass = 'Increment\Common\Payload\Http\PayloadController';
  function __construct()
  {
    $this->model = new Ledger();
    if ($this->checkAuthenticatedUser() == false) {
      return $this->response();
    }
    $this->localization();
  }

  public function generateCode()
  {
    $code = 'led_' . substr(str_shuffle($this->codeSource), 0, 60);
    $codeExist = Ledger::where('code', '=', $code)->get();
    if (sizeof($codeExist) > 0) {
      $this->generateCode();
    } else {
      return $code;
    }
  }

  public function getAllCurrencies($accountId, $accountCode){
    $currencies = Ledger::select('currency')->where('account_code', '=', $accountCode)->where('account_id', '=', $accountId)->orderBy('currency', 'ASC')->groupBy('currency')->get();
    if($currencies){
      $response = [];
      foreach ($currencies as $key => $value) {
        $response[] = $value['currency'];
      }
      return $response;
    }else{
      return array('PHP');
    }
  }

  public function dashboard(Request $request)
  {
    $data = $request->all();
    $result = array();
    $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
    if ($account == null) {
      $this->response['error'] = 'Invalid Access';
      $this->response['data'] = null;
      return $this->response();
    }
    $currencies = $this->getAllCurrencies($data['account_id'], $data['account_code']);
    foreach ($currencies as $key) {
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

    $history = Ledger::select('code', 'account_code', 'amount', 'description', 'currency', 'details', 'created_at')
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

  public function summary(Request $request)
  {
    $data = $request->all();
    $result = array();
    $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
    $currencies = $this->getAllCurrencies($data['account_id'], $data['account_code']);
    foreach ($currencies as $key) {
      if ($account == null) {
        $this->response['error'] = 'Invalid Access';
        $this->response['data'] = null;
        return $this->response();
      } else {
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

  public function getPendingAmount($accountId, $currency)
  {
    $total = 0;

    if (env('REQUEST') == true) {
      $total += app('App\Http\Controllers\RequestMoneyController')->getTotalActiveRequest($accountId, $currency);
    }

    if (env('DEPOSIT') == true) {
      $total += app('Increment\Finance\Http\DepositController')->getTotalByParams($accountId, $currency);
    }

    if (env('WITHDRAWAL') == true) {
      $total += app('Increment\Finance\Http\WithdrawalController')->getTotalByParams($accountId, $currency);
    }

    return $total;
  }

  public function getSum($accountId, $accountCode, $currency)
  {
    $total = Ledger::where('account_id', '=', $accountId)->where('account_code', '=', $accountCode)->where('currency', '=', $currency)->sum('amount');
    return $total;
  }

  public function addEntry($data)
  {
    $amount = Checkout::select("total")->where("id", $data["checkout_id"])->get();
    $entry = array();
    // $entry["payment_payload"] = $data["payment_payload"];
    // $entry["payment_payload_value"] = $data["payment_payload_value"];
    $entry["code"] = $this->generateCode();
    $entry["account_id"] = $data["account_id"];
    $entry["account_code"] = $data["account_code"];
    $entry["description"] = $data["status"];
    $entry["details"] = $data["details"];
    $entry["currency"] = $data["currency"];
    $entry["amount"] = $amount[0]["total"];
    $this->model = new Ledger();
    $this->insertDB($entry);
    return $this->response();
  }

  public function verify($data)
  {
    $result = Ledger::where('account_id', '=', $data['account_id'])
      ->where('account_code', '=', $data['account_code'])
      ->where('description', '=', $data['description'])
      ->where('amount', '=', $data['amount'])
      ->where('currency', '=', $data['details'])
      ->where('details', '=', $data['currency'])
      ->orderBy('created_at', 'desc')
      ->limit(1)
      ->get();
    if (sizeof($result) > 0) {
      $currentDate = Carbon::now();
      $createdAt = Carbon::createFromFormat('Y-m-d H:i:s', $result[0]['created_at']);

      $minutes = $currentDate->diffInMinutes($createdAt);
      if ($minutes >= 30) {
        return null;
      } else {
        return $result[0];
      }
    } else {
      return null;
    }
  }

  public function addNewEntry($data)
  {
    $duplicate = $this->verify($data);
    if ($duplicate) {
      return array(
        'error' => 'Duplicate Entry',
        'data'  => null
      );
    } else {
      $amount = $data['amount'];
      $entry = array();
      // $entry["payment_payload"] = $data["payment_payload"];
      // $entry["payment_payload_value"] = $data["payment_payload_value"];
      $entry["code"] = $this->generateCode();
      $entry["account_id"] = $data["account_id"];
      $entry["account_code"] = $data["account_code"];
      $entry["description"] = $data["description"];
      $entry["details"] = $data["details"];
      $entry["currency"] = $data["currency"];
      $entry["amount"] = $amount;
      $this->model = new Ledger();
      $this->insertDB($entry);

      if ($this->response['data'] > 0) {
        // run jobs here
        $parameter = array(
          'from'    => $data['from'],
          'to'      => $data['account_id'],
          'payload' => $data["description"],
          'payload_value' => $data['request_id'],
          'route'   => 'ledger/' . $data["payment_payload_value"],
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

  public function transfer(Request $request)
  {
    $data = $request->all();
    $amount = floatval($data['amount']);
    $this->response['error'] = null;
    // check account if exist
    // check if balance is sufficient
    // send otp on first request
    // verify otp on confirmation
    $myBalance = floatval($this->retrievePersonal($data['from_account_id'], $data['from_account_code'], $data['currency']));
    if ($myBalance < $amount) {
      $this->response['stage'] = 1;
      $this->response['error'] = 'You have insufficient balance. Your current balance is ' . $data['currency'] . ' ' . $myBalance . ' balance.';
      return $this->response();
    }
    if ($data['stage'] == 1) {
      app($this->notificationSettingClass)->generateOtpById($data['from_account_id']);
      $this->response['data'] = true;
      $this->response['stage'] = 2;
      return $this->response();
    }
    if ($data['stage'] == 2) {
      $notification = app($this->notificationSettingClass)->getByAccountIdAndCode($data['from_account_id'], $data['otp']);

      if ($notification == null) {
        $this->response['error'] = 'Invalid Code, please try again!';
        return $this->response();
      }

      $entry = [];
      $entry[] = array(
        // "payment_payload" => $data["payment_payload"],
        // "payment_payload_value" => $data["payment_payload_value"],
        "code" => $this->generateCode(),
        "account_id" => $data["account_id"],
        "account_code" => $data["account_code"],
        "description" => $data["description"],
        "currency" => $data["currency"],
        "amount" => $amount,
        'details' => $data['details'],
        'created_at' => Carbon::now()
      );

      if ($data['type'] == 'AUTOMATIC') {
        $entry[] = array(
          // "payment_payload" => $data["payment_payload"],
          // "payment_payload_value" => $data["payment_payload_value"],
          "code" => $this->generateCode(),
          "account_id" => $data["from_account_id"],
          "account_code" => $data["from_account_code"],
          "description" => $data['from_description'],
          "currency" => $data["currency"],
          'details' => $data['details'],
          "amount" => $amount * (-1),
          'created_at' => Carbon::now()
        );
      }

      // $this->model = new Ledger();

      // $this->insertDB($entry);
      $this->response['data'] = Ledger::insert($entry);

      if ($this->response['data'] > 0) {
        // send email here
        $this->response['stage'] = 3;
      }

      return $this->response();
    }
    return $this->response();
  }

  public function history(Request $request)
  {
    $data = $request->all();
    $account = app($this->accountClass)->getAccountIdByParamsWithColumns($data['account_code'], ['id', 'code']);
    if ($account == null) {
      $this->response['error'] = 'Invalid Access';
      $this->response['data'] = null;
      return $this->response();
    } else {
      $result = Ledger::select('code', 'account_code', 'amount', 'description', 'currency', 'details', 'created_at')
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

  public function retrieve(Request $request)
  {
    //grabs by code
    //TODO: add security check for admin to grab bulk data
    //returns only one because of code constraint
    $result = Ledger::select("ledgers.*", "merchants.account_id AS merchant_id", "merchants.name")
      ->where("ledgers.code", $request["code"])
      ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
      ->get();
    $result[0]["logo"] = Image::select()
      ->where("account_id", $result[0]["merchant_id"])
      ->get();
    return $result;
  }

  public function retrieveForMerchant(Request $request)
  {
    $result = Ledger::select(
      "ledgers.id AS ledger",
      "ledgers.code AS ledgerc",
      "ledgers.created_at AS ledger_created",
      "ledgers.updated_at AS ledger_updated",
      "ledgers.deleted_at AS ledger_delete",
      "ledgers.*",
      "merchants.*",
      "cash_methods.created_at AS cash_methods_created",
      "cash_methods.updated_at AS cash_methods_updated",
      "cash_methods.deleted_at AS cash_methods_deleted"
    )
      ->where("ledgers.account_code", $request["code"])
      ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
      ->leftJoin("cash_methods", "ledgers.payment_payload_value", "=", "cash_methods.code")
      ->limit($request['limit'])
      ->offset($request['offset'])
      ->get();
    return $result;
  }

  public function retrieveByID(Request $request)
  {
    //retrieves ledger entry by ID and passes ledger and merchant info
    $result = Ledger::select("ledgers.id AS ledger", "ledgers.code AS ledgerc", "ledgers.created_at AS ledger_created", "ledgers.updated_at AS ledger_updated", "ledgers.*", "merchants.*")
      ->where("ledgers.id", $request["id"])
      ->leftJoin('merchants', 'ledgers.account_id', "=", "merchants.account_id")
      ->get();
    return $result;
  }

  public function retrievePersonal($accountId, $accountCode, $currency)
  {
    $ledger = Ledger::where('account_id', '=', $accountId)->where('account_code', '=', $accountCode)->where('currency', '=', $currency)->sum('amount');
    $total = doubleval($ledger);
    return doubleval($total);
  }

  public function summaryLedger(Request $request)
  {
    $data = $request->all();
    $ledger = DB::table("ledgers")
      ->where('account_id', '=', $data['account_id'])
      ->select('code', 'account_id', 'account_code', 'amount', 'description', 'currency', 'details', 'created_at')
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


  public function transactionHistory(Request $request)
  {
    $data = $request->all();
    $con = $data['condition'];
    if ($con[0]['column'] === 'receiver.username' || $con[0]['column'] === 'sender.username') {
      $transactions = DB::table('ledgers as T1')
        ->leftJoin('accounts as T2', 'T2.account_id', '=', 'T1.id')
        ->where('T1.' . $con[0]['column'], $con[0]['clause'], $con[0]['value'])
        ->select("T1.*")
        ->limit((isset($data['limit']) ? $data['limit'] : 5))
        ->offset((isset($data['offset']) ? $data['offset'] : 5))
        ->orderBy('username', array_values($data['sort'])[0])
        ->get();
      $transactions = json_decode($transactions, true);
    } else {
      $transactions = Ledger::where($con[0]['column'], $con[0]['clause'], $con[0]['value'])
        ->select("ledgers.*")
        ->limit((isset($data['limit']) ? $data['limit'] : 5))
        ->offset((isset($data['offset']) ? $data['offset'] : 5))
        ->orderBy(array_keys($data['sort'])[0], array_values($data['sort'])[0])
        ->get();
    }
    $i = 0;
    foreach ($transactions as $key) {
      $transactions[$i]->created_at_human = Carbon::createFromFormat('Y-m-d H:i:s', $transactions[$i]->created_at)->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
      $transactions[$i]->receiver = $this->retriveAccountDetailsByCode($transactions[$i]->payment_payload_value);
      $transactions[$i]->owner = $this->retriveAccountDetailsByCode($transactions[$i]->account_code);
      $i++;
    }
    if (isset($data['condition'])) {
      $condition = $data['condition'];
      if (sizeof($condition) == 1) {
        $con = $condition[0];
        $this->response['size'] = Ledger::where('deleted_at', '=', null)->where($con['column'], $con['clause'], $con['value'])->count();
      }
      if (sizeof($condition) == 2) {
        $con = $condition[0];
        $con1 = $condition[1];
        if ($con1['clause'] != 'or') {
          $this->response['size'] = Ledger::where('deleted_at', '=', null)->where($con['column'], $con['clause'], $con['value'])->where($con1['column'], $con1['clause'], $con1['value'])->count();
        } else {
          $this->response['size'] = Ledger::where('deleted_at', '=', null)->where($con['column'], $con['clause'], $con['value'])->orWhere($con1['column'], '=', $con1['value'])->count();
        }
      }
    } else {
      $this->response['size'] = Ledger::where('deleted_at', '=', null)->count();
    }
    $this->response['data'] = $transactions;
    return $this->response();
  }

  public function directTransfer(Request $request)
  {
    $data = $request->all();
    $from = $data['from'];
    $to = $data['to'];
    $amount = floatval($data['amount']);
    $currency = $data['currency'];
    $notes = $data['notes'];
    $payload = $data['payload'];
    $charge = isset($data['charge']) ? $data['charge'] : 0;

    $fromEmail = $from['email'];
    $fromCode = $from['code'];

    $toEmail = $to['email'];
    $toCode = $to['code'];


    if ($fromEmail == null || $fromCode == null || $toEmail == null || $toCode == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Details';
      return $this->response();
    }

    if ($fromEmail == $toEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Transaction: The same account.';
      return $this->response();
    }


    if ($amount == null || ($amount & $amount <= 0) || $currency == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Details';
      return $this->response();
    }


    if ($fromEmail == $toEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Transaction: The same account.';
      return $this->response();
    }


    // from account details
    $fromAccount = $this->retriveAccountDetailsByCode($fromCode);

    if ($fromAccount == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Sender Account was not found!';
      return $this->response();
    }

    if ($fromAccount != null && $fromAccount['email'] != $fromEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Sender Account!';
      return $this->response();
    }

    $fromBalance = $this->retrievePersonal($fromAccount['id'], $fromAccount['code'], $currency);

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
    if ($toAccount == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Receiver Account was not found!';
      return $this->response();
    }

    if ($toAccount != null && $toAccount['email'] != $toEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Receiver Account!';
      return $this->response();
    }

    $resultSender = $this->addNewEntryDirectTransfer(
      array(
        "payment_payload" => $payload == 'direct_transfer' ? 'direct_transfer' : 'scan_payment',
        "payment_payload_value" => $toAccount['code'],
        'details' => json_encode(array(
          "payment_payload" => $payload == 'direct_transfer' ? 'direct_transfer' : 'scan_payment',
          "payment_payload_value" => $toAccount['code'],
          "account" => array(
            'id' => $toAccount['id'],
            'code' => $toAccount['code'],
          ),
          "type" => 'send'
        )),
        "code" => $this->generateCode(),
        "account_id" => $fromAccount["id"],
        "account_code" => $fromAccount["code"],
        "description" => $payload == 'direct_transfer' ? 'Direct Transfer' . ($notes != null ? ':' . $notes : null) : 'Scan Payment' . ($notes != null ? ':' . $notes : null),
        "currency" => $currency,
        "amount" => $amount * -1,
        "from"   => $toAccount['id']
      )
    );


    $resultReceiver = $this->addNewEntryDirectTransfer(
      array(
        "payment_payload" => $payload == 'direct_transfer' ? 'direct_transfer' : 'scan_payment',
        "payment_payload_value" => $fromAccount['code'],
        'details' => json_encode(array(
          "payment_payload" => $payload == 'direct_transfer' ? 'direct_transfer' : 'scan_payment',
          "payment_payload_value" => $fromAccount['code'],
          "account" => array(
            'id' => $fromAccount['id'],
            'code' => $fromAccount['code']
          ),
          "type" => 'receive'
        )),
        "code" => $this->generateCode(),
        "account_id" => $toAccount["id"],
        "account_code" => $toAccount["code"],
        "description" => $payload == 'direct_transfer' ? 'Direct Transfer' . ($notes != null ? ':' . $notes : null) : 'Scan Payment' . ($notes != null ? ':' . $notes : null),
        "currency" => $currency,
        "amount" => $amount,
        "from"   => $fromAccount['id']
      )
    );

    if ($resultSender['error'] != null) {
      $this->response['error'] = $result['error'];
      $this->response['data'] = $result['data'];
      return $this->response();
    }else{
      $this->sendNotification($resultSender['data'], $resultSender['details'], $resultSender['entry'],
      true);
    }

    if ($payload == 'scan_payment') {
      app($this->firebaseController)->sendNew(
        array(
          'data' => array(
            'from_account'    => $fromAccount,
            'to_account'      => $toAccount,
            'amount'  => $amount,
            'currency' => $currency,
            'notes'   => $notes,
            'charge'  => $charge,
            'transfer_status' => 'completed',
            'topic'   => 'Payhiram-' . $toAccount['id'],
            'payload' => 'payments'
          ),
          'notification' => array(
            'title' => 'Payment Notification',
            'body'  => 'Payment accepted by ' . $fromAccount['email'],
            'imageUrl' => env('DOMAIN') . 'increment/v1/storage/logo/logo.png'
          ),
          'topic'   => 'Payhiram-' . $toAccount['id']
        )
      );
    }

    if ($resultReceiver['error'] != null) {
      $this->response['error'] = $result['error'];
      $this->response['data'] = $result['data'];
      return $this->response();
    }

    if ($resultReceiver['error'] == null) {
      $this->response['error'] = null;
      $this->response['data'] = true;
      $this->sendNotification($resultReceiver['data'], $resultReceiver['details'], $resultReceiver['entry'], false);
      return $this->response();
    }
  }

  public function acceptPaymentConfirmation(Request $request)
  {
    $data = $request->all();

    $from = $data['from_code'];
    $fromEmail = $data['from_email'];
    $to = $data['to_code'];
    $toEmail = $data['to_email'];
    $amount = floatval($data['amount']);
    $currency = $data['currency'];
    $notes = $data['notes'];
    $charge = isset($data['charge']) ? $data['charge'] : 0;

    if ($from == null || $to == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Details';
      return $this->response();
    }

    if ($amount == null || ($amount & $amount <= 0) || $currency == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Details';
      return $this->response();
    }

    // from account details
    $fromAccount = $this->retriveAccountDetailsByCode($from);

    if ($fromAccount == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Sender Account was not found!';
      return $this->response();
    }

    if ($fromAccount != null && $fromAccount['email'] != $fromEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Sender Account!';
      return $this->response();
    }

    $fromBalance = $this->retrievePersonal($fromAccount['id'], $fromAccount['code'], $currency);

    if ($fromBalance < $amount) {
      $this->response['data'] = null;
      $this->response['error'] = 'Insufficient Balance!';
      return $this->response();
    }


    // to account details
    $toAccount = $this->retriveAccountDetailsByCode($to);
    if ($toAccount == null) {
      $this->response['data'] = null;
      $this->response['error'] = 'Receiver Account was not found!';
      return $this->response();
    }

    if ($toAccount != null && $toAccount['email'] != $toEmail) {
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Receiver Account!';
      return $this->response();
    }

    app($this->firebaseController)->sendNew(
      array(
        'data' => array(
          'from_account'    => $fromAccount,
          'to_account'      => $toAccount,
          'amount'  => $amount,
          'currency' => $currency,
          'notes'   => $notes,
          'charge'  => $charge,
          'transfer_status' => 'requesting',
          'topic'   => 'Payhiram-' . $toAccount['id'],
          'payload' => 'payments'
        ),
        'notification' => array(
          'title' => 'Payment Notification',
          'body'  => 'Accept payments from ' . $fromEmail,
          'imageUrl' => env('DOMAIN') . 'increment/v1/storage/logo/logo.png'
        ),
        'topic'   => 'Payhiram-' . $toAccount['id']
      )
    );
    $this->response['data'] = true;
    $this->response['error'] = null;
    return $this->response();
  }



  public function sendNotification($ledgerId, $details, $entry, $flag){
    try{
      if ($ledgerId > 0) {
        if ($flag == true) {
          $owner = $this->retriveAccountDetailsByCode($entry["account_code"]);
          $receive = $this->retriveAccountDetailsByCode($entry["payment_payload_value"]);
          $code = substr($entry['code'], 56);
          if ($entry['payment_payload'] == "direct_transfer") {
            $subject = 'Transfer';
            $mode = 'direct_transfer';
            app('App\Http\Controllers\EmailController')->transfer_fund_sender($owner['id'], $code, $entry, $subject, $receive['id'], $mode);
          } else {
            $subject = 'Payment';
            $mode = 'scan_payment';
            app('App\Http\Controllers\EmailController')->transfer_fund_sender($owner['id'], $code, $entry, $subject, $receive['id'], $mode);
          }
        }
        // run jobs here
        $parameter = array(
          'from'    => $details['from'],
          'to'      => $details['account_id'],
          'payload' => $details["description"],
          'payload_value' => $entry["code"],
          'route'   => 'ledger/' . $entry["code"],
          'created_at'  => Carbon::now()
        );
        app($this->notificationClass)->createByParams($parameter);
      }
    }catch(Exception $error){
      //
    }
  }
  public function addNewEntryDirectTransfer($data)
  {
    $result = $this->verify($data);
    if ($result) {
      return array(
        'error' => 'Duplicate Entry',
        'data'  => null
      );

    } else {
      $amount = $data['amount'];
      $entry = array();
      $entry["code"] = $data["code"];
      $entry['details'] = $data['details'];
      $entry["account_id"] = $data["account_id"];
      $entry["account_code"] = $data["account_code"];
      $entry["description"] = $data["description"];
      $entry["currency"] = $data["currency"];
      $entry["amount"] = $amount;
      $this->model = new Ledger();
      $this->insertDB($entry);
      $entry['payment_payload_value'] = $data['payment_payload_value'];
      $entry['payment_payload'] = $data['payment_payload'];

      
      return array(
        'data' => $this->response['data'],
        'entry' => $entry,
        'details' => $data,
        'error' => null
      );
    }
  }
}
