<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staticcities extends Model
{
    protected $table = 'static_cities';
    protected $fillable = [
        'id',
        'cityName',
        'countryName',
        'type',
        'fullRegionName',
        'mst_country_id',
        'mst_state_id'
    ];

    public function country()
    {
        return $this->hasOne(
            mst_country::class,
            'id',
            'mst_country_id'
        );
    }

    public function state()
    {
        return $this->hasOne(
            mst_state::class,
            'id',
            'mst_state_id'
        );
    }
}
