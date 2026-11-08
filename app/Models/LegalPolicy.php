<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalPolicy extends Model
{
    protected $table = 'legal_policies';

    /** Allowed policy types => display label */
    public const TYPES = [
        'privacy_policy'   => 'Privacy Policy',
        'terms_conditions' => 'Terms & Conditions',
    ];

    protected $fillable = [
        'AgencyID',
        'UserSysId',
        'updated_by',
        'type',
        'title',
        'content',
        'version',
        'is_active',
    ];

    protected $casts = [
        'AgencyID'   => 'integer',
        'UserSysId'  => 'integer',
        'updated_by' => 'integer',
        'version'    => 'integer',
        'is_active'  => 'boolean',
    ];
}