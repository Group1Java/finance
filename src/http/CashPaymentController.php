<?php

namespace Increment\Finance\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Models\CashPayment;

class CashPaymentController extends APIController
{
    function __construct(){
        $this->model= new CashPayment();
    }

    public function generateCode(){
        $code = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 32);
        $codeExist = CashPayment::where('id', '=', $code)->get();
        if(sizeof($codeExist) > 0){
          $this->generateCode();
        }else{
          return $code;
        }
    }
    
    public function addPayment(Request $request){
        $data = $request->all();
        $data["code"] = $this->generateCode();
        $data["status"] = "PENDING";
        $this->model = new CashPayment();
        $this->insertDB($data);
        $data["payment_payload"] = $data["payload"];
        $data["payment_payload_value"] = $data["code"];
        //TODO: Once payment added, add entry to ledger
        $test = app('Increment\Finance\Http\LedgerController')->addEntry($data);
        $test = $test->getData();
        $idfrominsert = new Request();
        $idfrominsert["id"] = $test->{"data"};
        $code = app('Increment\Finance\Http\LedgerController')->retrieveByID($idfrominsert);
        return $code;
        //return $this->response();
    }

    public function retrieve(Request $request){
        $entry = CashPayment::where('code', $request["code"])->get();
        return $entry;
    }

    public function updateStatus(Request $request){
        //check if ID is authenticated to grab data
        $data = $request->all();
        CashPayment::where('code', $request["code"])
        ->update(['status' =>  $request["status"]]);
        $entry = CashPayment::select()
        ->where('code', $request["code"])->get();
        //get updated values here then pass to ledger controller
        //TODO: Update DB once retrieved. Clarify if update value is from front-end.
        $entry[0]["payment_payload"] = $entry[0]["payload"];
        $entry[0]["payment_payload_value"] = $entry[0]["code"];
        $entry[0]["checkout_id"] = $entry[0]["checkout_id"];
        $entry[0]["account_id"] = $request["account_id"];
        $entry[0]["account_code"] = $request["account_code"];
        $entry[0]["code"] = $this->generateCode();
        $entry[0]["currency"] = $request["currency"];
        $test = app('Increment\Finance\Http\LedgerController')->addEntry($entry[0]);
        $test = $test->getData();
        $idfrominsert = new Request();
        $idfrominsert["id"] = $test->{"data"};
        $code = app('Increment\Finance\Http\LedgerController')->retrieveByID($idfrominsert);
        return $code;
    }
}