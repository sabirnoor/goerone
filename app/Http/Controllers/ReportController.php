<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MemberReportSupport;
use App\Models\LoyaltyProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Admin member reports
 *  1) Paid Members by Tier   (user_card -> loyalty_card -> loyalty_program)
 *  2) Registered Members     (users.GoerOne = 1, with "membership purchased?" state)
 *
 * Definitions (single source of truth - every number in both reports uses these):
 *  - "Paid" membership   = the plan's loyalty_program.membership_amount > 0.
 *                          A user_card row on a paid plan means payment succeeded
 *                          (activation is instant), so no payment table is needed.
 *  - Active              = user_card.status = 'active' AND (deactivation_date IS NULL OR >= NOW()).
 *                          deactivation_date is checked as well, because status can lag behind expiry.
 *  - Suspended           = user_card.status = 'suspended'.
 *  - Expired             = everything else: status 'inactive' (replaced by a newer purchase / renewal)
 *                          or 'active' with a passed deactivation_date.
 *  - One user_card row   = one purchase. A renewal is a new row, so purchases != unique customers;
 *                          unique customers are always COUNT(DISTINCT user_id).
 */
class ReportController extends Controller
{
    use MemberReportSupport;

    private const PAID_STATUS_SQL = "CASE
        WHEN user_card.status = 'active' AND (user_card.deactivation_date IS NULL OR user_card.deactivation_date >= NOW()) THEN 'active'
        WHEN user_card.status = 'suspended' THEN 'suspended'
        ELSE 'expired' END";

    /* ------------------------------------------------------------------ */
    /*  Report 1: Paid members by tier                                     */
    /* ------------------------------------------------------------------ */

    /** POST /loyalty/report/paid-members-tier  -> summary + tier breakdown (single aggregate query) */
    public function paidMembersByTier(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->paidFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $agencyId = $this->agencyId($request->user());

        // All paid tiers (also feeds the Tier dropdown, so it ignores the tier/status/date filters)
        $paidPrograms = LoyaltyProgram::where('membership_amount', '>', 0)
            ->orderBy('program_id')
            ->get(['program_id', 'program_name', 'membership_amount', 'is_active']);

        $agg = $this->paidBase($agencyId, $f)
            ->groupBy('lp.program_id')
            ->selectRaw("lp.program_id,
                COUNT(*) AS total,
                SUM(" . self::PAID_STATUS_SQL . " = 'active') AS active_count,
                SUM(" . self::PAID_STATUS_SQL . " = 'expired') AS expired_count,
                SUM(" . self::PAID_STATUS_SQL . " = 'suspended') AS suspended_count,
                COUNT(DISTINCT user_card.user_id) AS unique_customers")
            ->get()
            ->keyBy('program_id');

        $tiers = $paidPrograms
            ->when(!empty($f['program_id']), fn($c) => $c->where('program_id', $f['program_id']))
            ->map(function ($p) use ($agg) {
                $a = $agg->get($p->program_id);
                return [
                    'program_id'       => (int) $p->program_id,
                    'program_name'     => $p->program_name,
                    'membership_amount' => (float) $p->membership_amount,
                    'is_active'        => (int) $p->is_active,
                    'total'            => (int) ($a->total ?? 0),
                    'active'           => (int) ($a->active_count ?? 0),
                    'expired'          => (int) ($a->expired_count ?? 0),
                    'suspended'        => (int) ($a->suspended_count ?? 0),
                    'unique_customers' => (int) ($a->unique_customers ?? 0),
                ];
            })->values();

        // Summary is derived from the same tier rows -> cards, chart and table can never disagree.
        // Only unique customers needs its own query (a customer can hold several tiers).
        $uniqueCustomers = (int) $this->paidBase($agencyId, $f)
            ->selectRaw('COUNT(DISTINCT user_card.user_id) AS c')->value('c');

        return $this->ok([
            'summary' => [
                'total'            => $tiers->sum('total'),
                'active'           => $tiers->sum('active'),
                'expired'          => $tiers->sum('expired'),
                'suspended'        => $tiers->sum('suspended'),
                'unique_customers' => $uniqueCustomers,
            ],
            'tiers'        => $tiers,
            'tier_options' => $paidPrograms->map(fn($p) => [
                'program_id' => (int) $p->program_id, 'program_name' => $p->program_name,
            ])->values(),
        ]);
    }

    /** POST /loyalty/report/paid-members-tier/members?per_page=&page=  -> drill-down list (paginated) */
    public function paidMembersList(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->paidFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $rows = $this->paidMembersQuery($this->agencyId($request->user()), $f)->paginate($perPage);

        return $this->ok($rows);
    }

    /** POST /loyalty/report/paid-members-tier/export  -> streamed file of the member rows for the active filters */
    public function paidMembersExport(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->paidFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $query = $this->paidMembersQuery($this->agencyId($request->user()), $f);

        return $this->streamCsv(
            'paid-members-by-tier',
            ['Customer', 'Email', 'Mobile', 'Membership Tier', 'Fee', 'Card No', 'Purchased On', 'Valid From', 'Valid Till', 'Status'],
            $query,
            fn($r) => [
                $this->customerName($r), $r->email, $r->mobile, $r->program_name, $r->membership_amount,
                $r->card_number ?: $r->card_number, $r->created_at, $r->activation_date, $r->deactivation_date,
                ucfirst($r->membership_status),
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Report 2: Registered members (users.GoerOne = 1)                   */
    /* ------------------------------------------------------------------ */

    /** POST /loyalty/report/registered-members?per_page=&page=  -> summary + paginated list */
    public function registeredMembers(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->registeredFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $agencyId = $this->agencyId($request->user());
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        // Cards describe the date/keyword-filtered population and ignore the membership filter,
        // so the Active / Expired / Not-purchased split stays visible while a bucket is selected.
        $s = $this->registeredBase($agencyId, array_merge($f, ['membership' => null]))
            ->selectRaw("COUNT(*) AS total,
                COALESCE(SUM(" . $this->paidExists(true) . "), 0) AS active_count,
                COALESCE(SUM(" . $this->paidExists(false) . " AND NOT " . $this->paidExists(true) . "), 0) AS expired_count,
                COALESCE(SUM(NOT " . $this->paidExists(false) . "), 0) AS none_count")
            ->first();

        $total     = (int) $s->total;
        $purchased = (int) $s->active_count + (int) $s->expired_count;

        $rows = $this->registeredRowsQuery($agencyId, $f)->paginate($perPage);

        return $this->ok([
            'summary' => [
                'total'           => $total,
                'active'          => (int) $s->active_count,
                'expired'         => (int) $s->expired_count,
                'not_purchased'   => (int) $s->none_count,
                'conversion_rate' => $total > 0 ? round($purchased / $total * 100, 1) : 0,
            ],
            'members' => $rows,
        ]);
    }

    /** POST /loyalty/report/registered-members/export */
    public function registeredMembersExport(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->registeredFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $query = $this->registeredRowsQuery($this->agencyId($request->user()), $f);

        return $this->streamCsv(
            'registered-members',
            ['Customer', 'Email', 'Mobile', 'Registered On', 'Membership', 'Latest Paid Plan', 'Valid Till'],
            $query,
            fn($r) => [
                $this->customerName($r), $r->email, $r->mobile, $r->created_at,
                ['active' => 'Active', 'expired' => 'Expired / Inactive', 'none' => 'Not purchased'][$r->membership_state] ?? '',
                $r->plan_name, $r->plan_valid_till,
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Query builders                                                     */
    /* ------------------------------------------------------------------ */

    /** One row per purchase (user_card row) on a PAID plan. No reward/store joins -> no row multiplication. */
    private function paidBase(int $agencyId, array $f)
    {
        $q = DB::table('user_card')
            ->join('loyalty_card as lc', 'lc.card_id', '=', 'user_card.card_id')
            ->join('loyalty_program as lp', 'lp.program_id', '=', 'lc.program_id')
            ->where('lp.membership_amount', '>', 0)
            ->where('user_card.AgencyID', $agencyId);

        if (!empty($f['from_date'])) {
            $q->where('user_card.created_at', '>=', $f['from_date'] . ' 00:00:00');
        }
        if (!empty($f['to_date'])) {
            $q->where('user_card.created_at', '<=', $f['to_date'] . ' 23:59:59');
        }
        if (!empty($f['program_id'])) {
            $q->where('lp.program_id', $f['program_id']);
        }
        if (!empty($f['status'])) {
            $q->whereRaw('(' . self::PAID_STATUS_SQL . ') = ?', [$f['status']]);
        }
        return $q;
    }

    private function paidMembersQuery(int $agencyId, array $f)
    {
        $q = $this->paidBase($agencyId, $f)
            ->join('users', 'users.id', '=', 'user_card.user_id')
            ->select([
                'users.id as user_id', 'users.name', 'users.fname', 'users.lname', 'users.email', 'users.mobile',
                'lp.program_id', 'lp.program_name', 'lp.membership_amount',
                'user_card.card_no', 'user_card.card_number',
                'user_card.activation_date', 'user_card.deactivation_date', 'user_card.created_at',
            ])
            ->selectRaw(self::PAID_STATUS_SQL . ' AS membership_status')
            ->orderByDesc('user_card.created_at')
            ->orderByDesc('user_card.card_id');

        if (!empty($f['keyword'])) {
            $kw = '%' . $f['keyword'] . '%';
            $q->where(function ($w) use ($kw) {
                $w->where('users.name', 'like', $kw)->orWhere('users.fname', 'like', $kw)
                    ->orWhere('users.lname', 'like', $kw)->orWhere('users.email', 'like', $kw)
                    ->orWhere('users.mobile', 'like', $kw)->orWhere('user_card.card_number', 'like', $kw);
            });
        }
        return $q;
    }

    private function registeredBase(int $agencyId, array $f)
    {
        $q = DB::table('users')->where('users.GoerOne', 1)->where('users.AgencyID', $agencyId);

        if (!empty($f['from_date'])) {
            $q->where('users.created_at', '>=', $f['from_date'] . ' 00:00:00');
        }
        if (!empty($f['to_date'])) {
            $q->where('users.created_at', '<=', $f['to_date'] . ' 23:59:59');
        }
        if (!empty($f['keyword'])) {
            $kw = '%' . $f['keyword'] . '%';
            $q->where(function ($w) use ($kw) {
                $w->where('users.name', 'like', $kw)->orWhere('users.fname', 'like', $kw)
                    ->orWhere('users.lname', 'like', $kw)->orWhere('users.email', 'like', $kw)
                    ->orWhere('users.mobile', 'like', $kw);
            });
        }
        if (!empty($f['membership'])) {
            $q->whereRaw('(' . $this->registeredStateSql() . ') = ?', [$f['membership']]);
        }
        return $q;
    }

    private function registeredRowsQuery(int $agencyId, array $f)
    {
        return $this->registeredBase($agencyId, $f)
            ->select(['users.id', 'users.name', 'users.fname', 'users.lname', 'users.email', 'users.mobile', 'users.created_at'])
            ->selectRaw($this->registeredStateSql() . ' AS membership_state')
            ->selectRaw('(' . $this->latestPaidCard('lp.program_name') . ') AS plan_name')
            ->selectRaw('(' . $this->latestPaidCard('uc.deactivation_date') . ') AS plan_valid_till')
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id');
    }

    /** EXISTS(paid membership for users.id); $liveOnly = currently valid only. EXISTS cannot duplicate rows. */
    private function paidExists(bool $liveOnly): string
    {
        $live = $liveOnly
            ? " AND uc.status = 'active' AND (uc.deactivation_date IS NULL OR uc.deactivation_date >= NOW())"
            : '';
        return "EXISTS (SELECT 1 FROM user_card uc
                INNER JOIN loyalty_card lc ON lc.card_id = uc.card_id
                INNER JOIN loyalty_program lp ON lp.program_id = lc.program_id
                WHERE uc.user_id = users.id AND lp.membership_amount > 0{$live})";
    }

    private function registeredStateSql(): string
    {
        return "CASE WHEN " . $this->paidExists(true) . " THEN 'active'
                     WHEN " . $this->paidExists(false) . " THEN 'expired'
                     ELSE 'none' END";
    }

    private function latestPaidCard(string $column): string
    {
        return "SELECT {$column} FROM user_card uc
                INNER JOIN loyalty_card lc ON lc.card_id = uc.card_id
                INNER JOIN loyalty_program lp ON lp.program_id = lc.program_id
                WHERE uc.user_id = users.id AND lp.membership_amount > 0
                ORDER BY uc.created_at DESC, uc.card_id DESC LIMIT 1";
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function paidFilters(Request $request)
    {
        $v = Validator::make($request->all(), [
            'from_date'  => 'nullable|date_format:Y-m-d',
            'to_date'    => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'program_id' => 'nullable|integer|min:1',
            'status'     => 'nullable|in:active,expired,suspended',
            'keyword'    => 'nullable|string|max:100',
        ]);
        return $v->fails() ? $this->fail($v->errors()->first(), 422) : $v->validated();
    }

    private function registeredFilters(Request $request)
    {
        $v = Validator::make($request->all(), [
            'from_date'  => 'nullable|date_format:Y-m-d',
            'to_date'    => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'membership' => 'nullable|in:active,expired,none',
            'keyword'    => 'nullable|string|max:100',
        ]);
        return $v->fails() ? $this->fail($v->errors()->first(), 422) : $v->validated();
    }
}