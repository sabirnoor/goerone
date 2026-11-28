<?php

namespace App\Services;

use App\Models\NotificationMaster;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * In-app bell notifications for every audience (store, admin, staff, customer).
 * Not the same as GoerOneNotifier (WhatsApp / email); a notifier can call this for the bell.
 *
 * send() and sendToRole() never throw, so they are safe to call inside payment flows.
 */
class NotifyMasterBellIcon
{
    public const STORE    = 'store';
    public const ADMIN    = 'admin';
    public const STAFF    = 'staff';
    public const CUSTOMER = 'customer';

    /**
     * Resolve who the authenticated account is. Follows the existing convention:
     * a Store model has no UserType; users.UserType 1 = admin, 4 = staff, anything else = customer.
     *
     * @return array [recipient_type, recipient_id, AgencyID]
     */
    public static function recipientFor($user): array
    {
        if (!isset($user->UserType)) {
            return [self::STORE, (int) $user->id, (int) $user->AgencyID];
        }

        $userType = (int) $user->UserType;

        if ($userType === 1) {
            return [self::ADMIN, (int) $user->id, (int) $user->id];
        }
        if ($userType === 4) {
            return [self::STAFF, (int) $user->id, (int) $user->AgencyID];
        }

        return [self::CUSTOMER, (int) $user->id, (int) $user->AgencyID];
    }

    /**
     * Create one notification. Returns null when skipped (unknown type, duplicate, or failure).
     */
    public function send(
        string $type,
        string $recipientType,
        int $recipientId,
        int $agencyId,
        array $payload = [],
        ?string $dedupeKey = null
    ): ?NotificationMaster {
        try {
            $definition = config("notification_master.types.$type");
            if (!$definition) {
                Log::warning("NotifyMasterBellIcon: unknown notification type [$type]");
                return null;
            }

            $actionUrl = $payload['action_url']
                ?? (isset($definition['action_url']) ? $this->render($definition['action_url'], $payload) : null);

            return NotificationMaster::create([
                'AgencyID'       => $agencyId,
                'recipient_type' => $recipientType,
                'recipient_id'   => $recipientId,
                'type'           => $type,
                'title'          => $this->render($definition['title'], $payload),
                'message'        => $this->render($definition['message'], $payload),
                'data'           => $payload,
                'action_url'     => $actionUrl,
                'dedupe_key'     => $dedupeKey,
            ]);
        } catch (QueryException $e) {
            // 23000 = duplicate dedupe_key: the notification already exists, which is fine
            if ((string) $e->getCode() !== '23000') {
                Log::error('NotifyMasterBellIcon: insert failed - ' . $e->getMessage());
            }
            return null;
        } catch (\Throwable $e) {
            Log::error('NotifyMasterBellIcon: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fan out one row per person so read state stays individual.
     * $role: 'admin' (the agency owner) or 'staff' (UserType 4 under the agency).
     *
     * @return int number of notifications created
     */
    public function sendToRole(
        string $role,
        int $agencyId,
        string $type,
        array $payload = [],
        ?string $dedupeKey = null
    ): int {
        try {
            $query = User::without('details', 'agency', 'symbal')->where('active', 1);

            if ($role === self::ADMIN) {
                $query->where('id', $agencyId)->where('UserType', 1);
            } elseif ($role === self::STAFF) {
                $query->where('AgencyID', $agencyId)->where('UserType', 4);
            } else {
                return 0;
            }

            $count = 0;
            foreach ($query->pluck('id') as $id) {
                if ($this->send($type, $role, (int) $id, $agencyId, $payload, $dedupeKey)) {
                    $count++;
                }
            }
            return $count;
        } catch (\Throwable $e) {
            Log::error('NotifyMasterBellIcon::sendToRole - ' . $e->getMessage());
            return 0;
        }
    }

    // ---------------------------------------------------------------- bell feed (scoped to the caller)

    public function list($user, int $perPage = 20, bool $unreadOnly = false)
    {
        $query = $this->feed($user)->orderByDesc('id');
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        return $query->paginate($perPage);
    }

    public function unreadCount($user): int
    {
        return $this->feed($user)->whereNull('read_at')->count();
    }

    public function markRead($user, int $id): int
    {
        return $this->feed($user)->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function markAllRead($user): int
    {
        return $this->feed($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Schedule daily: deletes read notifications older than retention_days. */
    public static function prune(): int
    {
        $days = (int) config('notification_master.retention_days', 90);

        return NotificationMaster::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($days))
            ->delete();
    }

    // ---------------------------------------------------------------- internals

    private function feed($user)
    {
        [$type, $id] = self::recipientFor($user);

        return NotificationMaster::where('recipient_type', $type)->where('recipient_id', $id);
    }

    private function render(string $template, array $payload): string
    {
        $moneyKeys = config('notification_master.money_keys', []);
        $pairs = [];

        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $pairs['{' . $key . '}'] = (in_array($key, $moneyKeys, true) && is_numeric($value))
                ? number_format((float) $value, 2)
                : (string) $value;
        }

        return strtr($template, $pairs);
    }
}