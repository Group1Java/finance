<?php

namespace Increment\Finance\Stripe;

use Illuminate\Support\ServiceProvider;

class StripeServiceProvider extends ServiceProvider{

  public function boot(){
    $this->loadMigrationsFrom(__DIR__.'/migrations');
    $this->loadRoutesFrom(__DIR__.'/routes/web.php');
  }

  public function register(){
  }
}