<?php

namespace Increment\Finance\Http;

use Illuminate\Http\Request;
use Luigel\Paymongo\Facades\Paymongo;
use App\Http\Controllers\APIController;
use Increment\Finance\Models\Ledger;
use Increment\Common\Image\Models\Image;
use Increment\Imarket\Cart\Models\Checkout;
use Increment\Finance\Models\CardPayment;


class GCashController extends APIController
{
    //
    function __construct(){
        $this->model = new CardPayment();
        // $this->notRequired = array(
        //     'name', 'address', 'prefix', 'logo', 'website', 'email'
        // );
    }
 
    public function createPayment($details){
        $payment = Paymongo::payment()->create([
            'amount' => $details['amount'],
            'currency' => $details['currency'],
            'description' => $details['description'],
            'statement_descriptor' => $details['descriptor'],
            'source' => [
                'id' => $details['source_id'],
                'type' => 'source'
            ]
        ]);
        return $payment->getData();
    }

    public function createSource($details){
        $source = Paymongo::source()->create([
            'type' => 'gcash',
            'amount' => $details['amount'],
            'currency' => $details['currency'],
            'redirect' => [
                'success' => 'url-for-success',
                'failed' => 'url-for-failed'
            ]
        ]);
        return $source->getData();
    }

    //Render Gcash url 
    public function payByCreditCard(Request $request){
        $details = $request->all();
        $payables = $this->createPaymentIntent($details);
        $mop = $this->createPaymentMethod($details);
        $paymentIntent = Paymongo::paymentIntent()->find($payables['id']);
        $successfulPayment = $paymentIntent->attach($mop['id']);
        return $successfulPayment->getData();
    }
}