<?php

namespace Increment\Finance\Transfer\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\APIController;
use Increment\Finance\Transfer\Models\FundTransferCharge;
use Carbon\Carbon;

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
        return $this->response();
    }

    public function retrieve(Request $request)
    {
      $this->rawRequest = $request;
      $data = $request->all();
      $this->model = new FundTransferCharges();
      if (Cache::has('fundtransfer'.$request['scope'])){
        return Cache::get('fundtransfer'.$request['scope']);
      }else{
        $this->retrieveDB($data);
        $lifespan = Carbon::now()->addMinutes(3600);
        $keyname = "fundtransfer".$request['scope'];
        $charges = FundTransferCharge::where('code', '=', $data['code'])->get();
        if (sizeof($charges)>0){
          Cache::add($keyname, $charges, $lifespan);
          return $this->response();
        }
      }
    }
}
