<?php

namespace Increment\Finance\Transfer\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Transfer\Models\FundTransferCharge;

class FundTransferChargeController extends APIController
{
    function __construct(){
        $this->model = new FundTransferCharge();
    }

    public function generateCode(){
        $code = 'ftc_'.substr(str_shuffle($this->codeSource), 0, 60);
        $codeExist = FundTransferCharge::where('code', '=', $code)->get();
        if(sizeof($codeExist) > 0){
          $this->generateCode();
        }else{
          return $code;
        }
    }

    public function create(Request $request){
        $data = $request->all();
        $data['code'] = $this->generateCode();
        $this->model = new FundTransferCharge();
        $this->insertDB($data);
        $keyname = "fundtransfer".$request['scope'];
        $lifespan = Carbon::now()->addMinutes(3600);
        Cache::add($keyname, $data, $lifespan);
        return $this->response();
    }

    public function retrieve(Request $request)
    {
      $this->rawRequest = $request;
      if($this->checkAuthenticatedUser() == false){
        return $this->response();
      }
  
      $this->retrieveDB($request->all());
      return $this->response();
    }
    
}
