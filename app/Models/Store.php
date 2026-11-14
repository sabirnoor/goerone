<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Store extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'store_name',
        'email',
        'password',
        'address',
        'phone',
        'store_timings',
        'product_types',
        'enquiry_enabled',
        'payment_enabled',
        'is_halal',
        'food_type',
        'alcohol_allowed',
        'updated_by_store',
        'platform_fee',
        'platform_fee_payer',
        'qr_handle',
        'is_active',
        'bank_account_holder_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_name',
        'bank_branch',
        'bank_account_type',
        'bank_cancelled_cheque',
        'relation_manager',
        // self-registration
        'registration_status',
        'registration_source',
        'onboarding_step',
        'phone_verified_at',
        'gst_number',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'store_timings' => 'array',
        'product_types' => 'array',
        'enquiry_enabled' => 'boolean',
        'payment_enabled' => 'boolean',
        'is_halal' => 'boolean',
        'alcohol_allowed' => 'boolean',
        'platform_fee' => 'float',
        'is_active' => 'boolean',
        'relation_manager' => 'integer',
        'onboarding_step' => 'integer',
        'phone_verified_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /** stores.registration_status values */
    public const STATUS_DRAFT = 'draft';        // started onboarding, not submitted
    public const STATUS_PENDING = 'pending';    // submitted, waiting for admin
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** Wizard steps (1-5) then submit */
    public const ONBOARDING_STEPS = 5;

    /**
     * Mandatory details a store needs before it can be submitted or approved.
     * Used by the owner's submit AND by admin approving an incomplete (draft) store.
     * @return string[] labels of what is still missing (empty = complete)
     */
    public function missingRegistrationFields(): array
    {
        $required = [
            'store_name' => 'store name',
            'contact_name' => 'owner name',
            'vendor_category' => 'category',
            'address' => 'address',
            'state' => 'state',
            'city' => 'city',
            'pincode' => 'pincode',
        ];

        $missing = [];
        foreach ($required as $column => $label) {
            if (in_array(trim((string) $this->{$column}), ['', '0'], true)) {
                $missing[] = $label;
            }
        }

        $timings = $this->store_timings; // cast to array
        $hasOpenDay = is_array($timings) && collect($timings)->contains(fn ($d) => !empty($d['is_open']));
        if (!$hasOpenDay) {
            $missing[] = 'store timings';
        }

        // Map location: lat / lon must be real numbers and not the 0,0 placeholder
        $lat = is_numeric($this->lat) ? (float) $this->lat : null;
        $lon = is_numeric($this->lon) ? (float) $this->lon : null;
        if ($lat === null || $lon === null || ($lat == 0.0 && $lon == 0.0)) {
            $missing[] = 'map location';
        }

        return $missing;
    }

    public function isApproved(): bool
    {
        return $this->registration_status === self::STATUS_APPROVED;
    }

    /** Live for customers: approved by admin AND switched on */
    public function scopeLive($query)
    {
        return $query->where('stores.registration_status', self::STATUS_APPROVED)
            ->where('stores.is_active', 1);
    }

    /** Allowed values for stores.bank_account_type */
    public const BANK_ACCOUNT_TYPES = [
        'savings',
        'current',
        'salary',
        'nri',
        'fixed_deposit',
        'recurring_deposit',
    ];

    /** Default permanent QR handle for a store, e.g. store-42@goerone */
    public static function makeQrHandle($storeId): string
    {
        return 'store-' . (int) $storeId . '@goerone';
    }

    /** Relationship Manager (a staff user) assigned to this store by admin */
    public function relationshipManager()
    {
        return $this->belongsTo(User::class, 'relation_manager');
    }

    public function settlements()
    {
        return $this->hasMany(StoreSettlements::class);
    }

     public function images()
    {
        return $this->hasMany(StoreImage::class)->orderBy('sort_order');
    }

    public function agencyUser()
    {
        return $this->belongsTo(User::class, 'AgencyID');
    }
}