<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $table = 'credittransactions';
    protected $primaryKey = 'id';

    protected $fillable = [
        'CreditSystemID',
        'AgencyID',
        'UserSysId',
        'customer_id',
        'TransactionType',
        'Amount',
        'ReferenceID',
        'DueDate',
        'Status',
        'PaidAmount',
        'BalanceAmount',
        'Notes'
    ];

    protected $casts = [
        'DueDate' => 'date',
        'Amount' => 'decimal:2',
        'PaidAmount' => 'decimal:2',
        'BalanceAmount' => 'decimal:2',
    ];

    public function creditLimit()
    {
        return $this->belongsTo(AgentCreditLimit::class, 'CreditSystemID');
    }

    public function agency()
    {
        return $this->belongsTo(Users::class, 'AgencyID');
    }
    public function customer()
    {
        return $this->belongsTo(Users::class, 'customer_id');
    }

    public function payments()
    {
        return $this->hasMany(CreditPaymentHistory::class, 'CreditTransactionID');
    }

    public function walletEntries()
    {
        return $this->hasMany(WalletModel::class, 'CreditSystemID');
    }

    public function markAsOverdue()
    {
        if ($this->DueDate < now() && $this->Status === 'PENDING') {
            $this->Status = 'OVERDUE';
            $this->save();
        }
    }
}
