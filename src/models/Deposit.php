<?php

namespace Increment\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\APIModel;

class DepositPayment extends APIModel
{
    protected $table = 'deposits';
    protected $fillable = ['code', 'account_id', 'account_code', 'currency', 'amount', 'payload', 'payload_value'];
}