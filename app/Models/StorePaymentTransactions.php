<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StorePaymentTransactions extends Model
{
    use HasFactory;

    protected $table = 'store_payment_transactions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'txn_ref',
        'AgencyID',
        'payer_id',
        'payee_id',
        'type',
        'points',
        'description',
        'status',
        'latitude',
        'longitude',
        'device_id',
        'ip_address',
    ];

    protected $casts = [
        'points' => 'decimal:2',
    ];

    /**
     * Relationships
     */

    // 🧍‍♂️ The user who performed the transaction (payer / earner)
    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_id');
    }
    public function payee()
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    // 🏪 The store related to the transaction (if applicable)


    // 💰 Settlement relation (if a transaction is part of settlement summary)
    // public function settlement()
    // {
    //     return $this->hasOne(StoreSettlement::class, 'store_id', 'store_id')
    //                 ->whereDate('settlement_date', '=', $this->created_at->toDateString());
    // }

    /**
     * Scopes
     */

    // ✅ Filter by successful transactions
    public function scopeSuccess($query)
    {
        return $query->where('status', 'SUCCESS');
    }

    // 🧾 Filter by transaction type (PAY, EARN, etc.)
    public function scopeType($query, $type)
    {
        return $query->where('type', strtoupper($type));
    }

    /**
     * Accessors & Mutators
     */

    // Format points to 2 decimal places
    public function getPointsAttribute($value)
    {
        return number_format($value, 2, '.', '');
    }

    // Automatically uppercase the type
    public function setTypeAttribute($value)
    {
        $this->attributes['type'] = strtoupper($value);
    }

    /**
     * Utility Helpers
     */

    // Generate transaction reference (if not provided)
    public static function generateTxnRef(): string
    {
        return 'TXN' . now()->format('YmdHis') . rand(1000, 9999);
    }

    // Check if transaction belongs to a store payment
    public function isStorePayment(): bool
    {
        return $this->type === 'PAY' && !empty($this->store_id);
    }

    // Check if transaction is an earning transaction
    public function isRewardEarn(): bool
    {
        return $this->type === 'EARN';
    }
    public static function getPaymentHistory($User, $perPage, $post = array())
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $responsedata = StorePaymentTransactions::select(
            'store_payment_transactions.id',
            'store_payment_transactions.txn_ref',
            'store_payment_transactions.type',
            'store_payment_transactions.points',
            'store_payment_transactions.description',
            'store_payment_transactions.status',
            'store_payment_transactions.latitude',
            'store_payment_transactions.longitude',
            'users.countrycode',
            'users.mobile',
            'users.email',
            'users.fname',
            'users.lname',
            'users.title',
            'users.name',
            'users.UserType',
            'stores.store_name',
            'stores.vendortype',
        )->leftJoin('stores', 'stores.UserSysId', '=', 'store_payment_transactions.payee_id')
            ->leftJoin('users', 'users.id', '=', 'store_payment_transactions.payer_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('store_payment_transactions.AgencyID', $User->id);
                } else {
                    $query->where('store_payment_transactions.payer_id', $User->id);
                }
            })->where('store_payment_transactions.AgencyID', $AgencyID)
            ->orderBy('store_payment_transactions.id', 'DESC')->paginate($perPage);
        // ->toRawSql();
        // pr($responsedata);die;
        return $responsedata;
    }
    public static function getUserBalance($User, $post = array())
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $summary = StorePaymentTransactions::select(
            DB::raw('SUM(points) as availableBalance'),
            DB::raw('SUM(CASE WHEN DATE(created_at) = CURDATE() THEN points ELSE 0 END) as today_points')
        )->where(function ($query) use ($AgencyID) {
            $query->where('AgencyID', $AgencyID);
        })->where('payer_id', $User->id)->where('type', 'PAY')->where('status', 'SUCCESS')->first();
        return $summary;
    }
}
