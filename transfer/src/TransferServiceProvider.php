<?php

namespace Increment\Finance\Transfer;

use Illuminate\Support\ServiceProvider;

class TransferServiceProvider extends ServiceProvider{

  public function boot(){
    $this->loadMigrationsFrom(__DIR__.'/migrations');
    $this->loadRoutesFrom(__DIR__.'/routes/web.php');
  }

  public function register(){
  }
}