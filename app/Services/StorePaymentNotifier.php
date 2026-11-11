<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\WhatsappNotification\GoeroneNotificationService;
use Illuminate\Support\Facades\Log;

class StorePaymentNotifier
{
    public function __construct(private EmailService $emails) {}

    /** Send everything for one successful store payment. Never throws. */
   public function send(
    User $customer,
    int $storeId,
    float|string $amount,
    ?string $ref,
    string $ip,
    array $only = ['customer', 'store'],   // any of: 'customer', 'store', 'staff'
    ): void {
        $store = Store::find($storeId);

        if (!$store) {
            Log::warning('[StorePaymentNotifier] store not found', ['store_id' => $storeId, 'ref' => $ref]);
            return;
        }

        $data = $this->payload($customer, $store, $amount, $ref);

        if (in_array('customer', $only, true)) {
            $this->safely('customer', fn () => $this->notifyCustomer($customer, $data, $ip));
        }

        if (in_array('store', $only, true)) {
            $this->safely('store', fn () => $this->notifyStore($store, $data, $ip));
        }

        if (in_array('staff', $only, true)) {
            $this->safely('staff', fn () => $this->notifyStaff($store, $customer, $data));
        }
    }

    public function notifyStaff(Store $store, User $context, array $data): void
    {
        // Relationship manager assigned to this store (Store::relationshipManager)
        $rm    = $store->relationshipManager;
        $phone = $rm?->phone;   // adjust to your users phone column

        GoeroneNotificationService::notify(
            'goerone_store_payment_staff',
            $context,
            $data + ['staff_phone' => $phone],
            reference: $data['ref'],
            only: ['staff'],
        );
    }

    public function notifyCustomer(User $customer, array $data, string $ip): void
    {
        if (!empty($customer->email)) {
            $this->emails->sendEmail(
                $customer,
                $customer->email,
                'Payment Successful - GoerOne',
                view('emails.store_payment_customer', $data)->render(),
                $ip
            );
        }

        GoeroneNotificationService::notify(
            'goerone_store_payment_customer',
            $customer,
            $data + ['customer_phone' => $customer->mobile],   // adjust to your real column
            reference: $data['ref'],
            only: ['customer'],
        );
    }

    public function notifyStore(Store $store, array $data, string $ip): void
    {
        $sender = $this->resolveStoreSender($store);

        if (!$sender) {
            Log::warning('[StorePaymentNotifier] no agency user for store', [
                'store_id'  => $store->id,
                'agency_id' => $store->agency_id,
            ]);
            return;
        }

        // Prefer the store's own contact details, fall back to the agency user's
        $email = $store->email ?: $sender->email;
        $phone = $store->phone ?: $sender->phone;   // adjust to your users phone column

        if (!empty($email)) {
            $this->emails->sendEmail(
                $sender,
                $email,
                'Payment Received - ' . $store->store_name,
                view('emails.store_payment_merchant', $data)->render(),
                $ip
            );
        }

        GoeroneNotificationService::notify(
            'goerone_store_payment_partner',
            $sender,
            $data + ['store_phone' => $phone],
            reference: $data['ref'],
            only: ['store'],
        );
    }

    /** The users-table record that represents the store's agency */
    private function resolveStoreSender(Store $store): ?User
    {
        return $store->AgencyID ? User::find($store->AgencyID) : null;
    }

    /** One data array that satisfies the blades (payment_*) and the mappers (amount/ref). */
    private function payload(User $customer, Store $store, float|string $amount, ?string $ref): array
    {
        $formatted = 'INR ' . number_format((float) $amount, 2);

        return [
            'customer_name'  => $customer->name ?? 'Customer',
            'store_name'     => $store->store_name,
            'payment_amount' => $formatted,
            'payment_ref'    => $ref ?? '',
            'amount'         => $formatted,
            'ref'            => $ref ?? '',
        ];
    }

    private function safely(string $who, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("[StorePaymentNotifier] {$who} notification failed", ['error' => $e->getMessage()]);
        }
    }
}