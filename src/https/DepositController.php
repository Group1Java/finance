<?php

namespace Increment\Finance\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Models\Deposit;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class DepositController extends APIController
{
    //
    function __construct(){
      $this->model = new Deposit();
      if($this->checkAuthenticatedUser() == false){
        return $this->response();
      }
      $this->localization();
      $this->notRequired = array(
        'notes', 'tags', 'files'
      );
    }
    
    public function generateCode(){
      $code = 'dep_'.substr(str_shuffle($this->codeSource), 0, 60);
      $codeExist = Deposit::where('code', '=', $code)->get();
      if(sizeof($codeExist) > 0){
        $this->generateCode();
      }else{
        return $code;
      }
    }

    public function retrieveRequests(Request $request){
      $data = $request->all();
      $this->model = new Deposit();
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
      $this->response['size'] = Deposit::count();
      return $this->response();
    }

    public function create(Request $request){
        $data = $request->all();
        $data['code'] = $this->generateCode();
        $data['status'] = 'pending';
        $this->model = new Deposit();
        $this->insertDB($data);
        return $this->response();
    }

}