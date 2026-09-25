<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class Vouchers extends Model
{
    use HasFactory;

    protected $table = 'vouchers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'voucher_name',
        'voucher_price',
        'AgencyID',
        'UserSysId',
        'store_id',
        'no_of_voucher',
        'customer_type',
        'discount_type',
        'discount_value',
        'max_discount_value',
        'gtcoin_required',
        'required_value',
        'valid_from',
        'valid_to',
        'is_active',
        'terms_condition'
    ];

    protected $casts = [
        'voucher_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'max_discount_value' => 'decimal:2',
        'required_value' => 'decimal:2',
    ];
    /**
     * Relationships
     */

    /** Vouchers that can be sold on the portal right now */
    public function scopePurchasable(Builder $q): Builder
    {
        return $q->where('is_active', 1)
            ->where(fn($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', today()))
            ->where(fn($w) => $w->whereNull('no_of_voucher')
                ->orWhere('no_of_voucher', 0)
                ->orWhereColumn('sold_count', '<', 'no_of_voucher'));
    }

    public function isUnlimited(): bool
    {
        return empty($this->no_of_voucher);
    }

    /** null = unlimited */
    public function remainingStock(): ?int
    {
        return $this->isUnlimited() ? null : max(0, (int) $this->no_of_voucher - (int) $this->sold_count);
    }

    public function isPurchasable(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->valid_to && \Illuminate\Support\Carbon::parse($this->valid_to)->endOfDay()->isPast()) {
            return false;
        }
        $remaining = $this->remainingStock();
        return $remaining === null || $remaining > 0;
    }


    public function agency()
    {
        return $this->belongsTo(User::class, 'AgencyID');
    }

    public static function getVoucher($User, $perPage, $post = array())
    {

        $responsedata = Vouchers::select(
            'vouchers.id',
            'vouchers.voucher_name',
            'vouchers.voucher_price',
            DB::raw('DATE_FORMAT(vouchers.created_at, "%d %b, %Y") as createdDate'),
            'vouchers.AgencyID',
            'vouchers.UserSysId',
            'vouchers.store_id',
            'vouchers.no_of_voucher',
            'vouchers.customer_type',
            'vouchers.discount_type',
            'vouchers.discount_value',
            'vouchers.max_discount_value',
            'vouchers.gtcoin_required',
            'vouchers.required_value',
            'vouchers.valid_from',
            'vouchers.valid_to',
            'vouchers.is_active',
            'vouchers.terms_condition',
            'vouchers.created_at',
            'stores.store_name',
            'stores.email as store_email',
        )
            ->leftJoin('stores', 'stores.id', '=', 'vouchers.store_id')
            ->where(function ($query) use ($User) {
                if ($User->UserType == 1) {
                    $query->where('vouchers.AgencyID', $User->id);
                } else {
                    $query->where('vouchers.UserSysId', $User->id);
                }
            })
            ->orderBy('vouchers.id', 'DESC')
            ->paginate($perPage);

        return $responsedata;
    }
    public static function getVoucherAPI($User, $perPage, $post = array())
    {
        $customer_id = $User->id;
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $cardExists = LoyaltyUserCard::select('user_card.card_id', 'user_card.card_number', 'user_card.card_id', 'loyalty_card.program_id', 'loyalty_card.card_type')
            ->join('loyalty_card', 'loyalty_card.card_id', '=', 'user_card.card_id')
            ->where(function ($query) use ($User) {
                $query->where('user_card.AgencyID', $User->AgencyID);
            })->where('user_card.status', 'active')->where('user_card.user_id', $customer_id)->where('loyalty_card.status', 'active')->first();
        $program_id = isset($cardExists->program_id) ? $cardExists->program_id : 0;
        $responsedata = Vouchers::select(
            'vouchers.id',
            'vouchers.voucher_name',
            'vouchers.voucher_price',
            DB::raw('DATE_FORMAT(vouchers.created_at, "%d %b, %Y") as createdDate'),
            'vouchers.AgencyID',
            'vouchers.UserSysId',
            'vouchers.store_id',
            'vouchers.no_of_voucher',
            'vouchers.customer_type',
            'vouchers.discount_type',
            'vouchers.discount_value',
            'vouchers.max_discount_value',
            'vouchers.gtcoin_required',
            'vouchers.required_value',
            'vouchers.valid_from',
            'vouchers.valid_to',
            'vouchers.is_active',
            'vouchers.terms_condition',
            'vouchers.created_at',
            'stores.store_name',
            'stores.email as store_email',
        )->leftJoin('stores', 'stores.id', '=', 'vouchers.store_id')
            ->where(function ($query) use ($User) {
                $query->where('vouchers.AgencyID', $User->AgencyID);
            })->where(function ($query) use ($program_id) {
                $customerTypes = match ($program_id) {
                    0 => [3],          // only customer_type = 3
                    6 => [2, 3],        // include 2 + 3
                    3 => [1, 3],        // include 1 + 3
                    default => null
                };
                $query->when($customerTypes, function ($q) use ($customerTypes) {
                    $q->whereIn('vouchers.customer_type', $customerTypes);
                });
            })
            ->orderBy('vouchers.id', 'DESC')
            ->paginate($perPage);

        return $responsedata;
    }

    public static function getActiveVouchersByStore($storeId, $perPage, $page, $User = null)
    {
        $now = Carbon::now();

        $query = Vouchers::select(
            'vouchers.id',
            'vouchers.voucher_name',
            'vouchers.voucher_price',
            'vouchers.store_id',
            'vouchers.no_of_voucher',
            'vouchers.customer_type',
            'vouchers.discount_type',
            'vouchers.discount_value',
            'vouchers.max_discount_value',
            'vouchers.gtcoin_required',
            'vouchers.required_value',
            'vouchers.valid_from',
            'vouchers.valid_to',
            'vouchers.is_active',
            'vouchers.terms_condition'
        )
            ->where('vouchers.store_id', $storeId)
            ->where('vouchers.is_active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('vouchers.valid_from')
                    ->orWhere('vouchers.valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('vouchers.valid_to')
                    ->orWhere('vouchers.valid_to', '>=', $now);
            });

        if (!empty($User)) {
            $query->where(function ($q) use ($User) {
                if ($User->UserType == 1) {
                    $q->where('vouchers.AgencyID', $User->id);
                } else {
                    $q->where('vouchers.UserSysId', $User->id);
                }
            });
        }

        return $query->orderBy('vouchers.id', 'DESC')
            ->paginate($perPage, ['*'], 'voucher_page', $page);
    }
}
