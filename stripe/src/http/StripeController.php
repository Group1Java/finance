<?php

namespace Increment\Finance\Stripe\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Finance\Stripe\Models\StripeWebhooks as Stripe;
class StripeController extends APIController
{
  protected $pk;
  protected $sk;
  protected $stripe = null;
  protected $customer = null;

  function __construct(){
    $this->pk = env('STRIPE_PK');
    $this->sk = env('STRIPE_SK');

    $this->stripe = new Stripe($this->pk, $this->sk);
  }

  public function chargeCustomer(Request $request){
    $data = $request->all();

    if($this->stripe == null){
      $this->response['data'] = null;
      $this->response['error'] = 'Invalid Stripe Credentials';
      return $this->response();
    }

    $this->customer = $this->stripe->createCustomer($data['email'], $data['source']['id'], $data['name']);

    if($this->customer){
      $title = ' '.$data['plan']['title'];
      $charge = $this->stripe->chargeCustomer($data['email'], $data['source']['id'], $this->customer->id, $data['plan']['total'] * 100, $title);

      // save customer to the database
      // save charge to the database
      // save to plan with start and end date

      $this->response['data'] = array(
        'charge' => json_encode($charge),
        'customer' => json_encode($this->customer)
      );

      return $this->response();
    }else{
      return $this->response();
    }
  }


}