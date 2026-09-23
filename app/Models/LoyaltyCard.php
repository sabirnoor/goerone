<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyCard extends Model
{
    protected $table = 'loyalty_card';
    protected $fillable = [
        '*',
    ];
    protected $primaryKey = 'card_id';

    public function assignments()
    {
        return $this->hasMany(LoyaltyUserCard::class, 'card_id', 'card_id');
    }
    public function LoyaltyProgram()
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id', 'program_id');
    }
    public static function getUnAssignloyaltycard($User, $perPage, $post = array())
    {
        // $unassignedCards = LoyaltyCard::doesntHave('assignments')->get();

        $responsedata = LoyaltyCard::select(
            'loyalty_card.*',
            DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description'
        )->doesntHave('assignments')
            ->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('loyalty_card.AgencyID', $User->id);
                } else {
                    $query->where('loyalty_card.AgencyID', $User->AgencyID);
                }
            })->where(function ($query) use ($post) {
                if (count($post['cardNumbers']) > 0) {
                    $query->whereIn('loyalty_card.card_number', $post['cardNumbers']);
                }
                if (isset($post['program_id']) && $post['program_id'] > 0) {
                    $query->where('loyalty_card.program_id', $post['program_id']);
                }
                if ($post['keyword'] !== "null" && !empty($post['keyword'])) {
                    $keyword = $post['keyword'];
                    $query->where('loyalty_card.card_number', "like", "%" . $keyword . "%");
                    $query->orWhere('loyalty_program.program_name', "like", "%" . $keyword . "%");
                    $query->orWhere('loyalty_card.card_type', "like", "%" . $keyword . "%");
                }
            })->orderBy('loyalty_card.card_id', 'DESC')->paginate($perPage);
        return $responsedata;
    }
    public static function getUnAssignloyaltycardAuto($User, $perPage, $post = array())
    {
        // $unassignedCards = LoyaltyCard::doesntHave('assignments')->get();

        $responsedata = LoyaltyCard::select(
            'loyalty_card.*',
            DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description'
        )->doesntHave('assignments')
            ->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('loyalty_card.AgencyID', $User->id);
                } else {
                    $query->where('loyalty_card.AgencyID', $User->AgencyID);
                }
            })->where(function ($query) use ($post) {
                if (count($post['cardNumbers']) > 0) {
                    $query->whereIn('loyalty_card.card_number', $post['cardNumbers']);
                }
                if (isset($post['program_id']) && $post['program_id'] > 0) {
                    $query->where('loyalty_card.program_id', $post['program_id']);
                }
                if ($post['keyword'] !== "null" && !empty($post['keyword'])) {
                    $keyword = $post['keyword'];
                    $query->where('loyalty_card.card_number', "like", "%" . $keyword . "%");
                    $query->orWhere('loyalty_program.program_name', "like", "%" . $keyword . "%");
                    $query->orWhere('loyalty_card.card_type', "like", "%" . $keyword . "%");
                }
            })->orderBy('loyalty_card.card_id', 'ASC')->paginate($perPage);
        return $responsedata;
    }

    public static function getloyaltycard($User, $perPage, $post = array())
    {
        $responsedata = LoyaltyCard::select(
            'loyalty_card.*',
            DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
            //DB::raw('DATE_FORMAT(loyalty_card.issue_date, "%d %b, %Y") as issue_date'),
            //DB::raw('DATE_FORMAT(loyalty_card.expiration_date, "%d %b, %Y") as expiration_date'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description'
        )->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('loyalty_card.AgencyID', $User->id);
                } else {
                    $query->where('loyalty_card.UserSysId', $User->id);
                }
            })->where(function ($query) use ($post) {
                if (count($post['cardNumbers']) > 0) {
                    $query->whereIn('loyalty_card.card_number', $post['cardNumbers']);
                }
            })->orderBy('loyalty_card.card_id', 'ASC')->paginate($perPage);
        return $responsedata;
    }
    public static function getcardDetails($User, $card_id)
    {
        $responsedata = LoyaltyCard::select(
            'loyalty_card.*',
            DB::raw('DATE_FORMAT(loyalty_card.created_at, "%d %b, %Y") as createdDate'),
            'loyalty_program.program_name',
            'loyalty_program.description as program_description'
        )->leftjoin('loyalty_program', 'loyalty_program.program_id', '=', 'loyalty_card.program_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('loyalty_card.AgencyID', $User->id);
                } else {
                    $query->where('loyalty_card.AgencyID', $User->AgencyID);
                }
            })->where('loyalty_card.card_id', $card_id)->first();
        return $responsedata;
    }
}
