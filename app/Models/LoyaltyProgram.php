<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LoyaltyProgram extends Model
{
    protected $table = 'loyalty_program';
	protected $fillable = [
		'*',
	];

	protected $casts = [
        'services' => 'array',
        'invitation_required' => 'boolean',
        'membership_amount' => 'decimal:2',
        'welcome_coin' => 'integer',
    ];

	public static function getMembershipList($perPage, $post = array())
    {
        $responsedata = LoyaltyProgram::select(
            'loyalty_program.*',
            DB::raw('DATE_FORMAT(loyalty_program.created_at, "%d %b, %Y") as createdDate')
        )->where(function ($query) use ($post) {
            if (!empty($post['keyword']) && $post['keyword'] !== "null") {
                $keyword = $post['keyword'];
                $query->where('loyalty_program.program_name', 'like', '%' . $keyword . '%');
            }
            if (isset($post['is_active']) && $post['is_active'] !== "" && $post['is_active'] !== null) {
                $query->where('loyalty_program.is_active', $post['is_active']);
            }
        })->orderBy('loyalty_program.program_id', 'DESC')->paginate($perPage);
        return $responsedata;
    }
 
    public static function getMembershipDetails($program_id)
    {
        return LoyaltyProgram::where('program_id', $program_id)->first();
    }

    public static function getloyaltycard($User, $post = array())
	{
		$responsedata = LoyaltyCard::select(
			'loyalty_card.*',
			DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
		)->where(function ($query) use ($User) {
				if ($User->UserType == 1) {
					$query->where('loyalty_card.AgencyID', $User->id);
				} else {
					$query->where('loyalty_card.UserSysId', $User->id);
				}
			})->orderBy('loyalty_card.card_id', 'DESC')->limit(25)->get();
		return $responsedata;
	}
}
