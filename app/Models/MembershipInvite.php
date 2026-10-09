<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's "request consideration" application for an invite-only membership.
 * Table: membership_invites (program_id -> loyalty_program.program_id, user_id / reviewed_by -> users.id)
 */
class MembershipInvite extends Model
{
    protected $table = 'membership_invites';
    protected $primaryKey = 'id';

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    protected $fillable = [
        'program_id',
        'user_id',
        'cibil_score',
        'no_criminal_record',
        'income',
        'pan_number',
        'pan_verified',
        'consent_given',
        'consented_at',
        'photo_path',
        'photo_latitude',
        'photo_longitude',
        'social_media_details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'program_id' => 'integer',
        'user_id' => 'integer',
        'cibil_score' => 'integer',
        'no_criminal_record' => 'boolean',
        'income' => 'decimal:2',
        'pan_verified' => 'boolean',
        'consent_given' => 'boolean',
        'consented_at' => 'datetime',
        'photo_latitude' => 'float',
        'photo_longitude' => 'float',
        'social_media_details' => 'array',
        'status' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute()
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
        ][$this->status] ?? 'Unknown';
    }

    public function program()
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id', 'program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Pending or approved request this customer already holds for the plan (rejected ones don't block a re-apply). */
    public static function activeFor($userId, $programId)
    {
        return static::where('user_id', $userId)
            ->where('program_id', $programId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->latest('id')
            ->first();
    }

    /** Admin list: filter by status / plan / PAN, newest first. */
    public static function getInviteList($perPage, $post)
    {
        $query = static::with(['program:program_id,program_name', 'user', 'reviewer'])->orderByDesc('id');

        if (isset($post['status']) && $post['status'] !== '' && $post['status'] !== null) {
            $query->where('status', (int) $post['status']);
        }
        if (!empty($post['program_id'])) {
            $query->where('program_id', $post['program_id']);
        }
        if (!empty($post['keyword'])) {
            $query->where('pan_number', 'like', '%' . strtoupper($post['keyword']) . '%');
        }

        return $query->paginate($perPage);
    }
}