<?php
namespace Increment\Finance\Stripe\Models;
use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Product;
use Stripe\Plan;
use Stripe\Customer;  
use Stripe\Subscription;
use Stripe\Refund;
use Stripe\Coupon;
use Stripe\Discount;

class StripeWebhooks{

    protected $pk;
    protected $sk;
    function __construct($pk, $sk){
      $this->pk = $pk;
      $this->sk = $sk;
      Stripe::setApiKey($this->sk);
    }

    public function createCustomer($email, $sourceId, $name){
      $customer = Customer::create(array(
        'email'   => $email,
        'description' => $name,
        'source'  => $sourceId
      ));
      return $customer;
    }

    public function chargeCustomer($email, $sourceId, $customerId, $amount, $title){
      $charge = Charge::create(array(
        'amount'      => $amount,
        'currency'    => 'usd',
        'description' => 'Charge for '.$email.$title,
        'source'      => $sourceId,
        'customer'    => $customerId,
        'receipt_email' => $email
      ));
      return $charge;
    }

    public function createSubscription($customerId, $planId, $trialPeriod){
      $subscription = Subscription::create(array(
        'customer' => $customerId,
        'items'    => array(array('plan'  => $planId)),
        'trial_period_days' => $trialPeriod
      ));
      return $subscription;
    }

    public function createSubscriptionWithCoupon($customerId, $planId, $trialPeriod, $couponId){
      $subscriptionWithCoupon = Subscription::create(array(
        'customer'  => $customerId,
        'items'  => array(array('plan' => $planId)),
        'trial_period_days' => $trialPeriod,
        'coupon' => $couponId
      ));
      return $subscriptionWithCoupon;
    }

    public function retrieveSubscriptionCurrentEndDate($subscriptionId){
      $subscription = Subscription::retrieve($subscriptionId);
      return $subscription->current_period_end;
    }

    public function retrieveSubscriptionCurrentStartDate($subscriptionId){
      $subscription = Subscription::retrieve($subscriptionId);
      return $subscription->current_period_start;
    }

    public function cancelSubscription($subscriptionId){
      $subscription = Subscription::retrieve($subscriptionId);
      $subscription->cancel();
      return $subscription;
    }

    public function updateSubcription($customerId, $subscriptionId, $newPlanId){
      $subscription = Subscription::retrieve($subscriptionId);
      $subscription->prorate = true;
      $subscription->items = array(array('plan' => $newPlanId));
      // $subscription->customer = $customerId;
      $subscription->save();
    }

    public function retrieveCoupon($couponId){
      $coupon = Coupon::retrieve($couponId);
      return $coupon;
    }

    public function addSourceToCustomer($customer, $source){
      $customerDetails = Customer::retrieve($customer['id']);
      $customer->sources->create(array('source' => $source->id));
    }


    public function createPlan($product, $data){
      $plan = Plan::create(array(
        'currency'  => 'usd',
        'interval'  => 'month',
        'product'   => $product->id,
        'nickname'  => $data['plan'],
        'amount'    => $data['amount']
      ));
      return $plan;
    }

    public function refundCustomer($charge, $amount){
      if($amount == null || $amount == '' || intval($amount) == 0){
        $refund = Refund::create(array(
          'charge'  => $charge['id'],
        ));
        return $refund;
      }else{
        $refund = Refund::create(array(
          'charge'  => $charge['id'],
          'amount'  => $amount
        ));
        return $refund;
      }
    }
}
?>