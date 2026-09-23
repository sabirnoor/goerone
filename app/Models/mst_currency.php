<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class mst_currency extends Model
{
	protected $table = 'mst_currency';
	protected $fillable = [
		'id'
	];
	protected $hidden = ['updated_at'];
}
