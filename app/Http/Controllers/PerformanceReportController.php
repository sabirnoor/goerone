<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MemberReportSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Admin performance reports built on the `redemption` table (+ user_card for membership):
 *  1) Activation rate      - % of paid members whose first benefit (first completed redemption) happens within 30/60/90 days
 *  2) Active transactors   - active paid members who transacted in the last N days (default 30)
 *  3) Commission           - commission collected (discount) vs actual commission earned
 *                            customer_discount_by_goerone = store_discount - discountCust
 *                            actual earned                = discount - customer_discount_by_goerone
 *
 * Rules shared by all three:
 *  - Only redemption.status = 'completed' counts (pending / cancelled / refunded are ignored).
 *  - "Paid member" = user_card row on a plan with loyalty_program.membership_amount > 0.
 *  - "Active"      = user_card.status = 'active' AND (deactivation_date IS NULL OR >= NOW()).
 *  - Everything is scoped to redemption.AgencyID / user_card.AgencyID of the logged-in agency.
 */
class PerformanceReportController extends Controller
{
    use MemberReportSupport;

    /** Name of the redemption table - confirm against your schema. */
    private const R = 'redemption';

    private const WINDOWS = [30, 60, 90];

    private const LIVE_SQL = "user_card.status = 'active' AND (user_card.deactivation_date IS NULL OR user_card.deactivation_date >= NOW())";

    /** Discount GoerOne funds for the customer = store_discount - discountCust */
    private const GOERONE_DISC_SQL = 'r.store_discount - r.discountCust';

    /**
     * Actual commission earned = commission collected (discount) - customer discount by GoerOne.
     * NOTE: written as collected - goerone discount (the other way round is negative whenever the commission
     * exceeds the discount). Flip here only if the business really wants it reversed.
     */
    private const EARNED_SQL = 'r.discount - (r.store_discount - r.discountCust)';

    /* ================================================================== */
    /*  1. Activation rate                                                 */
    /* ================================================================== */

    /** POST /loyalty/report/activation  -> summary (30/60/90 windows) + monthly cohorts */
    public function activation(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->validate2($request, [
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date'   => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
        ]);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $agencyId = $this->agencyId($request->user());
        $agg = $this->activationAggregates();

        $s = DB::query()->fromSub($this->activationMembers($agencyId, $f), 'm')->selectRaw($agg)->first();

        $cohorts = DB::query()->fromSub($this->activationMembers($agencyId, $f), 'm')
            ->selectRaw("DATE_FORMAT(m.first_activation, '%Y-%m') AS cohort, " . $agg)
            ->groupBy('cohort')->orderByDesc('cohort')->limit(24)->get();

        return $this->ok([
            'summary' => array_merge([
                'total'          => (int) $s->total,
                'activated_ever' => (int) $s->activated_ever,
                'not_activated'  => (int) $s->total - (int) $s->activated_ever,
                'avg_days'       => $s->avg_days !== null ? round((float) $s->avg_days, 1) : null,
            ], ['windows' => $this->windows($s)]),
            'cohorts' => $cohorts->map(fn($c) => [
                'cohort'         => $c->cohort,
                'total'          => (int) $c->total,
                'activated_ever' => (int) $c->activated_ever,
                'windows'        => $this->windows($c),
            ])->values(),
        ]);
    }

    /** POST /loyalty/report/activation/members?per_page=&page=  (state: activated|not_activated|within_30|within_60|within_90) */
    public function activationMembersList(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->activationListFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return $this->ok($this->activationListQuery($this->agencyId($request->user()), $f)->paginate($perPage));
    }

    public function activationExport(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->activationListFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }

        return $this->streamCsv(
            'activation-rate',
            ['Customer', 'Email', 'Mobile', 'First Paid Activation', 'First Benefit Used', 'Days To First Benefit'],
            $this->activationListQuery($this->agencyId($request->user()), $f),
            fn($r) => [$this->customerName($r), $r->email, $r->mobile, $r->first_activation, $r->first_txn, $r->days_to_first]
        );
    }

    private function activationListFilters(Request $request)
    {
        return $this->validate2($request, [
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date'   => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'state'     => 'nullable|in:activated,not_activated,within_30,within_60,within_90',
        ]);
    }

    /**
     * One row per paid CUSTOMER, anchored at the activation of their FIRST paid membership
     * (renewals/upgrades do not create a new cohort entry), with the date of their first completed
     * redemption on/after that activation.
     */
    private function activationMembers(int $agencyId, array $f)
    {
        $first = DB::table('user_card')
            ->join('loyalty_card as lc', 'lc.card_id', '=', 'user_card.card_id')
            ->join('loyalty_program as lp', 'lp.program_id', '=', 'lc.program_id')
            ->where('lp.membership_amount', '>', 0)
            ->where('user_card.AgencyID', $agencyId)
            ->groupBy('user_card.user_id')
            ->havingRaw('MIN(user_card.activation_date) IS NOT NULL')
            ->selectRaw('user_card.user_id, MIN(user_card.activation_date) AS first_activation');

        if (!empty($f['from_date'])) {
            $first->havingRaw('MIN(user_card.activation_date) >= ?', [$f['from_date'] . ' 00:00:00']);
        }
        if (!empty($f['to_date'])) {
            $first->havingRaw('MIN(user_card.activation_date) <= ?', [$f['to_date'] . ' 23:59:59']);
        }

        return DB::query()->fromSub($first, 'fp')
            ->select('fp.user_id', 'fp.first_activation')
            ->selectRaw('(SELECT MIN(r.redemption_date) FROM ' . self::R . ' r
                          WHERE r.user_id = fp.user_id AND r.AgencyID = ? AND r.status = ?
                            AND r.redemption_date >= fp.first_activation) AS first_txn', [$agencyId, 'completed']);
    }

    /**
     * Windows only count MATURE members: a member activated 10 days ago cannot have had 30 days
     * to use a benefit yet, so for the N-day rate the denominator is members activated >= N days ago.
     */
    private function activationAggregates(): string
    {
        $parts = [
            'COUNT(*) AS total',
            'COALESCE(SUM(m.first_txn IS NOT NULL), 0) AS activated_ever',
            'AVG(CASE WHEN m.first_txn IS NOT NULL THEN TIMESTAMPDIFF(DAY, m.first_activation, m.first_txn) END) AS avg_days',
        ];
        foreach (self::WINDOWS as $n) {
            $parts[] = "COALESCE(SUM(m.first_activation <= NOW() - INTERVAL {$n} DAY), 0) AS eligible_{$n}";
            $parts[] = "COALESCE(SUM(m.first_txn IS NOT NULL
                          AND m.first_txn <= m.first_activation + INTERVAL {$n} DAY
                          AND m.first_activation <= NOW() - INTERVAL {$n} DAY), 0) AS activated_{$n}";
        }
        return implode(', ', $parts);
    }

    private function windows($row): array
    {
        return collect(self::WINDOWS)->map(function ($n) use ($row) {
            $eligible  = (int) $row->{"eligible_{$n}"};
            $activated = (int) $row->{"activated_{$n}"};
            return [
                'days'      => $n,
                'eligible'  => $eligible,
                'activated' => $activated,
                'rate'      => $eligible > 0 ? round($activated / $eligible * 100, 1) : null,
            ];
        })->all();
    }

    private function activationListQuery(int $agencyId, array $f)
    {
        $q = DB::query()->fromSub($this->activationMembers($agencyId, $f), 'm')
            ->join('users', 'users.id', '=', 'm.user_id')
            ->select(['users.id as user_id', 'users.name', 'users.fname', 'users.lname', 'users.email', 'users.mobile', 'm.first_activation', 'm.first_txn'])
            ->selectRaw('CASE WHEN m.first_txn IS NULL THEN NULL ELSE TIMESTAMPDIFF(DAY, m.first_activation, m.first_txn) END AS days_to_first')
            ->orderByDesc('m.first_activation')->orderByDesc('m.user_id');

        $state = $f['state'] ?? null;
        if ($state === 'activated') {
            $q->whereNotNull('m.first_txn');
        } elseif ($state === 'not_activated') {
            $q->whereNull('m.first_txn');
        } elseif ($state && preg_match('/^within_(30|60|90)$/', $state, $mt)) {
            $n = (int) $mt[1]; // whitelisted by validation + regex
            $q->whereNotNull('m.first_txn')
                ->whereRaw("m.first_txn <= m.first_activation + INTERVAL {$n} DAY")
                ->whereRaw("m.first_activation <= NOW() - INTERVAL {$n} DAY");
        }
        return $q;
    }

    /* ================================================================== */
    /*  2. Active members who transacted in the last N days                */
    /* ================================================================== */

    /** POST /loyalty/report/active-transactors  -> summary + tier breakdown */
    public function activeTransactors(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->transactorFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $agencyId = $this->agencyId($request->user());
        $days  = (int) ($f['days'] ?? 30);
        $since = now()->subDays($days)->format('Y-m-d H:i:s');
        $exists = $this->transactedExists();
        $b = [$agencyId, $since];

        $programs = DB::table('loyalty_program')->where('membership_amount', '>', 0)->orderBy('program_id')
            ->get(['program_id', 'program_name']);

        $tierRows = $this->liveMembers($agencyId, $f)
            ->groupBy('lp.program_id')
            ->selectRaw("lp.program_id,
                COUNT(DISTINCT user_card.user_id) AS members,
                COUNT(DISTINCT CASE WHEN {$exists} THEN user_card.user_id END) AS transacted", $b)
            ->get()->keyBy('program_id');

        $tiers = $programs
            ->when(!empty($f['program_id']), fn($c) => $c->where('program_id', (int) $f['program_id']))
            ->map(function ($p) use ($tierRows) {
                $t = $tierRows->get($p->program_id);
                $members = (int) ($t->members ?? 0);
                $transacted = (int) ($t->transacted ?? 0);
                return [
                    'program_id'    => (int) $p->program_id,
                    'program_name'  => $p->program_name,
                    'members'       => $members,
                    'transacted'    => $transacted,
                    'not_transacted' => $members - $transacted,
                    'rate'          => $members > 0 ? round($transacted / $members * 100, 1) : null,
                ];
            })->values();

        // Distinct across tiers (one customer is counted once even if data holds two live cards)
        $o = $this->liveMembers($agencyId, $f)->selectRaw("
                COUNT(DISTINCT user_card.user_id) AS members,
                COUNT(DISTINCT CASE WHEN {$exists} THEN user_card.user_id END) AS transacted", $b)->first();

        $t = DB::table(self::R . ' as r')
            ->where('r.AgencyID', $agencyId)->where('r.status', 'completed')->where('r.redemption_date', '>=', $since)
            ->whereIn('r.user_id', $this->liveMembers($agencyId, $f)->select('user_card.user_id'))
            ->selectRaw('COUNT(*) AS txns, COALESCE(ROUND(SUM(r.order_amount), 2), 0) AS order_value')->first();

        $members = (int) $o->members;
        $transacted = (int) $o->transacted;

        return $this->ok([
            'days'    => $days,
            'summary' => [
                'members'        => $members,
                'transacted'     => $transacted,
                'not_transacted' => $members - $transacted,
                'rate'           => $members > 0 ? round($transacted / $members * 100, 1) : null,
                'transactions'   => (int) $t->txns,
                'order_value'    => (float) $t->order_value,
            ],
            'tiers'        => $tiers,
            'tier_options' => $programs->map(fn($p) => ['program_id' => (int) $p->program_id, 'program_name' => $p->program_name])->values(),
        ]);
    }

    /** POST /loyalty/report/active-transactors/members?per_page=&page=  (state: transacted|not_transacted) */
    public function transactorsList(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->transactorFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return $this->ok($this->transactorsQuery($this->agencyId($request->user()), $f)->paginate($perPage));
    }

    public function transactorsExport(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->transactorFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }

        return $this->streamCsv(
            'active-members-transactions',
            ['Customer', 'Email', 'Mobile', 'Membership Tier', 'Valid Till', 'Last Transaction', 'Transactions In Window', 'Order Value In Window'],
            $this->transactorsQuery($this->agencyId($request->user()), $f),
            fn($r) => [$this->customerName($r), $r->email, $r->mobile, $r->program_name, $r->deactivation_date, $r->last_txn_at, $r->txn_count, $r->order_value]
        );
    }

    private function transactorFilters(Request $request)
    {
        return $this->validate2($request, [
            'days'       => 'nullable|integer|min:1|max:365',
            'program_id' => 'nullable|integer|min:1',
            'state'      => 'nullable|in:transacted,not_transacted',
        ]);
    }

    /** Active paid memberships (one row per live paid user_card). Renewal flips the old card to inactive, so one live card per customer. */
    private function liveMembers(int $agencyId, array $f)
    {
        $q = DB::table('user_card')
            ->join('loyalty_card as lc', 'lc.card_id', '=', 'user_card.card_id')
            ->join('loyalty_program as lp', 'lp.program_id', '=', 'lc.program_id')
            ->where('lp.membership_amount', '>', 0)
            ->where('user_card.AgencyID', $agencyId)
            ->whereRaw(self::LIVE_SQL);

        if (!empty($f['program_id'])) {
            $q->where('lp.program_id', (int) $f['program_id']);
        }
        return $q;
    }

    /** EXISTS(completed redemption in window) - bindings: [agencyId, since] */
    private function transactedExists(): string
    {
        return 'EXISTS (SELECT 1 FROM ' . self::R . " r WHERE r.user_id = user_card.user_id
                AND r.AgencyID = ? AND r.status = 'completed' AND r.redemption_date >= ?)";
    }

    private function transactorsQuery(int $agencyId, array $f)
    {
        $days  = (int) ($f['days'] ?? 30);
        $since = now()->subDays($days)->format('Y-m-d H:i:s');
        $r = self::R;
        $win = [$agencyId, $since];

        $q = $this->liveMembers($agencyId, $f)
            ->join('users', 'users.id', '=', 'user_card.user_id')
            ->select(['users.id as user_id', 'users.name', 'users.fname', 'users.lname', 'users.email', 'users.mobile',
                'lp.program_id', 'lp.program_name', 'user_card.deactivation_date'])
            ->selectRaw("(SELECT MAX(r.redemption_date) FROM {$r} r WHERE r.user_id = user_card.user_id AND r.AgencyID = ? AND r.status = 'completed') AS last_txn_at", [$agencyId])
            ->selectRaw("(SELECT COUNT(*) FROM {$r} r WHERE r.user_id = user_card.user_id AND r.AgencyID = ? AND r.status = 'completed' AND r.redemption_date >= ?) AS txn_count", $win)
            ->selectRaw("(SELECT COALESCE(ROUND(SUM(r.order_amount), 2), 0) FROM {$r} r WHERE r.user_id = user_card.user_id AND r.AgencyID = ? AND r.status = 'completed' AND r.redemption_date >= ?) AS order_value", $win)
            ->orderByRaw('last_txn_at IS NULL, last_txn_at DESC')
            ->orderByDesc('user_card.user_id');

        $state = $f['state'] ?? null;
        if ($state === 'transacted') {
            $q->whereRaw($this->transactedExists(), $win);
        } elseif ($state === 'not_transacted') {
            $q->whereRaw('NOT ' . $this->transactedExists(), $win);
        }
        return $q;
    }

    /* ================================================================== */
    /*  3. Commission collected vs earned                                  */
    /* ================================================================== */

    /**
     * POST /loyalty/report/commission
     *   collected        = SUM(discount)                                   (commission charged)
     *   goerone discount = SUM(store_discount - discountCust)              (customer discount by GoerOne)
     *   earned           = collected - goerone discount                    (actual commission earned)
     */
    public function commission(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->commissionFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $groupBy = $f['group_by'] ?? 'month';
        if ($groupBy === 'day') {
            if (empty($f['from_date']) || empty($f['to_date']) || \Carbon\Carbon::parse($f['from_date'])->diffInDays($f['to_date']) > 92) {
                return $this->fail('Daily view needs a date range of up to 92 days.', 422);
            }
        }
        $fmt = $groupBy === 'day' ? '%Y-%m-%d' : '%Y-%m';
        $e = self::EARNED_SQL;
        $g = self::GOERONE_DISC_SQL;

        $series = $this->commissionBase($this->agencyId($request->user()), $f)
            ->selectRaw("DATE_FORMAT(r.redemption_date, '{$fmt}') AS period,
                COUNT(*) AS txns,
                ROUND(SUM(r.order_amount), 2) AS order_value,
                ROUND(SUM(r.discount), 2) AS collected,
                ROUND(SUM({$g}), 2) AS goerone_discount,
                ROUND(SUM({$e}), 2) AS earned,
                ROUND(SUM(CASE WHEN r.settlement_status = 'settled' THEN r.discount ELSE 0 END), 2) AS collected_settled")
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn($p) => [
                'period'            => $p->period,
                'transactions'      => (int) $p->txns,
                'order_value'       => (float) $p->order_value,
                'collected'         => (float) $p->collected,
                'goerone_discount'  => (float) $p->goerone_discount,
                'earned'            => (float) $p->earned,
                'collected_settled' => (float) $p->collected_settled,
            ]);

        // Summary = sum of the series rows, so cards, chart and table always agree.
        $collected = round($series->sum('collected'), 2);
        $earned    = round($series->sum('earned'), 2);
        $goerone   = round($series->sum('goerone_discount'), 2);
        $settled   = round($series->sum('collected_settled'), 2);

        return $this->ok([
            'group_by' => $groupBy,
            'summary'  => [
                'transactions'      => $series->sum('transactions'),
                'order_value'       => round($series->sum('order_value'), 2),
                'collected'         => $collected,
                'goerone_discount'  => $goerone,
                'earned'            => $earned,
                'earned_pct'        => $collected > 0 ? round($earned / $collected * 100, 1) : null,
                'collected_settled' => $settled,
                'collected_pending' => round($collected - $settled, 2),
            ],
            'series' => $series->values(),
        ]);
    }

    /** POST /loyalty/report/commission/ledger?per_page=&page=  -> transaction-level rows */
    public function commissionLedger(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->commissionFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return $this->ok($this->commissionLedgerQuery($this->agencyId($request->user()), $f)->paginate($perPage));
    }

    public function commissionExport(Request $request)
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $f = $this->commissionFilters($request);
        if ($f instanceof \Illuminate\Http\JsonResponse) {
            return $f;
        }

        return $this->streamCsv(
            'commission-collected-vs-earned',
            ['Date', 'Reference No', 'Store ID', 'Customer', 'Order Amount', 'Commission Collected', 'Store Discount', 'Customer Discount', 'Customer Discount By GoerOne', 'Commission Earned', 'Settlement'],
            $this->commissionLedgerQuery($this->agencyId($request->user()), $f),
            fn($r) => [$r->redemption_date, $r->referneceNo, $r->stores_id, $this->customerName($r), $r->order_amount,
                $r->commission_collected, $r->store_discount, $r->discountCust, $r->customer_discount_by_goerone, $r->commission_earned, $r->settlement_status]
        );
    }

    private function commissionFilters(Request $request)
    {
        return $this->validate2($request, [
            'from_date'         => 'nullable|date_format:Y-m-d',
            'to_date'           => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'group_by'          => 'nullable|in:day,month',
            'settlement_status' => 'nullable|in:pending,settled',
        ]);
    }

    private function commissionBase(int $agencyId, array $f)
    {
        $q = DB::table(self::R . ' as r')->where('r.AgencyID', $agencyId)->where('r.status', 'completed');

        if (!empty($f['from_date'])) {
            $q->where('r.redemption_date', '>=', $f['from_date'] . ' 00:00:00');
        }
        if (!empty($f['to_date'])) {
            $q->where('r.redemption_date', '<=', $f['to_date'] . ' 23:59:59');
        }
        if (!empty($f['settlement_status'])) {
            $q->where('r.settlement_status', $f['settlement_status']);
        }
        return $q;
    }

    private function commissionLedgerQuery(int $agencyId, array $f)
    {
        return $this->commissionBase($agencyId, $f)
            ->leftJoin('users', 'users.id', '=', 'r.user_id')
            ->select(['r.redemption_id', 'r.referneceNo', 'r.redemption_date', 'r.stores_id', 'users.name', 'users.fname', 'users.lname',
                'r.order_amount', 'r.discount as commission_collected', 'r.store_discount', 'r.discountCust', 'r.settlement_status'])
            ->selectRaw('ROUND(' . self::GOERONE_DISC_SQL . ', 2) AS customer_discount_by_goerone')
            ->selectRaw('ROUND(' . self::EARNED_SQL . ', 2) AS commission_earned')
            ->orderByDesc('r.redemption_date')->orderByDesc('r.redemption_id');
    }

    /* ------------------------------------------------------------------ */

    private function validate2(Request $request, array $rules)
    {
        $v = Validator::make($request->all(), $rules);
        return $v->fails() ? $this->fail($v->errors()->first(), 422) : $v->validated();
    }
}