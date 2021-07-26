<?php

// Finances
// Charges Controller
$route = env('PACKAGE_ROUTE', '').'/stripe_webhooks/';
$controller = 'Increment\Finance\Stripe\Http\StripeController@';
Route::post($route.'charge_customer', $controller."chargeCustomer");
Route::get($route.'test', $controller."test");

