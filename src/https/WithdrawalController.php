<?php

namespace Increment\Finance\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Models\Withdrawal;
use Carbon\Carbon;
class WithdrawalController extends APIController
{

  public $ledgerClass = 'Increment\Finance\Http\LedgerController';
  public $notificationSettingClass = 'App\Http\Controllers\NotificationSettingController';

  function __construct(){
    $this->model = new Withdrawal();
    if($this->checkAuthenticatedUser() == false){
      return $this->response();
    }
    $this->localization();
    $this->notRequired = array(
      'notes'
    );
  }

  public function create(Request $request){
    $data = $request->all();
    $amount = floatval($data['amount']) + floatval($data['charge']);
    $myBalance = floatval(app($this->ledgerClass)->retrievePersonal($data['account_id'], $data['account_code'], $data['currency']));
    if($myBalance < $amount){
      $this->response['error'] = 'You have insufficient balance. Your current balance is '.$data['currency'].' '.$myBalance.' balance.';
      return $this->response();
    }
    if($data['stage'] == 1){
      app($this->notificationSettingClass)->generateOtpById($data['account_id']);
      $this->response['data'] = true;
      return $this->response();
    }
    if($data['stage'] == 2){
      $notification = app($this->notificationSettingClass)->getByAccountIdAndCode($data['account_id'], $data['otp']);
      if($notification == null){
        $this->response['error'] = 'Invalid Code, please try again!';
        return $this->response();
      }
      $this->model = new Withdrawal();
      $data['status'] = 'pending';
      $data['code'] = $this->generateCode();
      $this->insertDB($data);
      if($this->response['data'] > 0){
        // send email here
      }
      return $this->response();
    }
  }

   public function retrieveRequests(Request $request){
      $data = $request->all();
      $this->model = new Withdrawal();
      $this->retrieveDB($data);
      $result = $this->response['data'];
      if(sizeof($result) > 0){
        $i = 0;
        foreach ($result as $key) {
          $this->response['data'][$i]['name'] = $this->retrieveNameOnly($key['account_id']);
          $this->response['data'][$i]['date_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y H:i');
          $i++;
        }
      }
      $this->response['size'] = Withdrawal::count();
      return $this->response();
    }

  public function generateCode(){
    $code = 'wid_'.substr(str_shuffle($this->codeSource), 0, 60);
    $codeExist = Withdrawal::where('code', '=', $code)->get();
    if(sizeof($codeExist) > 0){
      $this->generateCode();
    }else{
      return $code;
    }
  }
}