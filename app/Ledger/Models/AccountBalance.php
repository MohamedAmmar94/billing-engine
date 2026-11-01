<?php

declare(strict_types=1);

namespace App\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class AccountBalance extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $fillable = ['account_id', 'debit_total', 'credit_total'];
}
