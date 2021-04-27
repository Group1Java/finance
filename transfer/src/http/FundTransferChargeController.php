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
        $this->notRequired = array(
          'effective_date', 'scope'
        );
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
        // dd($request);
        $data = $request->all();
        $this->model = new FundTransferCharge();
        $code = $this->generateCode();
        $params = array(
          'code' => $code,
          'currency' => $data['currency'],
          'charge' => $data['charge'],
          'minimum_amount' => $data['minimum_amount'],
          'maximum_amount' => $data['maximum_amount'],
          'destination' => $data['destination'],
          'scope' => $data['scope'],
          'effective_date' => $data['effective_date']
        );
        $this->insertDB($params);
        return $this->response();
    }

    public function retrieveAll(Request $request){
      $data = $request->all();
      // if (Cache::has('fundtransfer'.$request['scope'])){
      //   return Cache::get('fundtransfer'.$request['scope']);
      // }else{
        $this->retrieveDB($data);
        $result = $this->response['data'];
        $i = 0;
        foreach ($result as $key) {
          // $result[$i]['type'] = $key['destination'];
          $result[$i]['max_amount'] = $key['maximum_amount'];
          $result[$i]['min_amount'] = $key['minimum_amount'];
          $result[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
          $i++;
        }
        $this->response['data'] = $result;
        return $this->response(); 
      // }
    }


    public function retrieve(Request $request)
    {
      $this->rawRequest = $request;
      $data = $request->all();
      $this->model = new FundTransferCharge();
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
