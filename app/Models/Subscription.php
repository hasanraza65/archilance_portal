<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
     use HasFactory;

    protected $fillable = [
        'user_id', 'plan_id', 'stripe_subscription_id', 'stripe_customer_id',
        'status', 'starts_at', 'ends_at',
    ];

    protected $dates = ['starts_at', 'ends_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function billingDetail()
    {
        return $this->belongsTo(BillingDetail::class,'user_id','user_id');
    }
}
