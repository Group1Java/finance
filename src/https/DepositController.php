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
      $code = 'led_'.substr(str_shuffle($this->codeSource), 0, 60);
      $codeExist = Deposit::where('code', '=', $code)->get();
      if(sizeof($codeExist) > 0){
        $this->generateCode();
      }else{
        return $code;
      }
    }

    public function create(Request $request){
        $data = $request->all();
        $data['code'] = $this->generateCode();
        $this->model = new Deposit();
        $this->insertDB($data);
        return $this->response();
    }

}