<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class mst_state extends Model
{
    protected $table = 'mst_state';
    protected $fillable = [
        'id',
        'countryid',
        'stateId',
        'name',
    ];
    protected $hidden = ['created_at', 'updated_at'];

    public function country()
    {
        return $this->hasOne(
            mst_country::class,
            'id',
            'countryid'
        );
    }
}
