<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class incorporation_details extends Model
{
    protected $table = 'incorporation_details';
    protected $fillable = [
        '*',
    ];
    protected $with = ['referralUser'];

    public function referralUser(): HasOne
    {
        return $this->hasOne(
            self::class,    // Points to the same model
            'UserSysId',     // Foreign key in the same table (the target record)
            'referral_user_id' // Local key (current record's referral field)
        )->select(['UserSysId', 'referral_earning']); // Only these fields
    }
    public function RMUser()
    {
        return $this->belongsTo(User::class, 'RmUserID', 'id');
    }
    public function markupplacename()
    {
        return $this->hasOne(mst_markups::class, 'id', 'MarketPlaceID');
    }
    public function FlexiPenalty()
    {
        return $this->hasMany(FlexiPenalty::class, 'mst_markups_id', 'MarketPlaceID');
    }

    public static function companydetail($UserSysId, $AgencyID)
    {
        return incorporation_details::where('UserSysId', $UserSysId)->where('AgencyID', $AgencyID)->first();
    }
}
