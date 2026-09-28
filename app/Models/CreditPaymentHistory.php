<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPaymentHistory extends Model
{
	protected $table = 'creditpaymenthistory';
	protected $primaryKey = 'id';

	protected $fillable = [
		'CreditTransactionID',
		'PaymentAmount',
		'PaymentDate',
		'PaymentMode',
		'ReferenceNo',
		'Notes'
	];

	protected $casts = [
		'PaymentDate' => 'date',
		'PaymentAmount' => 'float',
	];

	public function creditTransaction()
	{
		return $this->belongsTo(CreditTransaction::class, 'CreditTransactionID');
	}
}
