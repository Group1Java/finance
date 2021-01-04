<?php

// Finances
// Charges Controller
$route = env('PACKAGE_ROUTE', '').'/fund_transfer_charges/';
$controller = 'Increment\Finance\Transfer\Http\FundTransferChargeController@';
Route::post($route.'create', $controller."create");
Route::post($route.'retrieve', $controller."retrieve");
Route::post($route.'retrieve_all', $controller."retrieveAll");
Route::post($route.'update', $controller."update");
Route::post($route.'delete', $controller."delete");
Route::get($route.'test', $controller."test");

