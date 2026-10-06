<?php

namespace App\Http\Resources\Voucher;

use Illuminate\Http\Resources\Json\JsonResource;

class VoucherOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'order_no'     => $this->order_no,
            'status'       => $this->status,
            'subtotal'     => (float) $this->subtotal,
            'tax_amount'   => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'discount' => (float) $this->customer_share,
            'currency'     => $this->currency,
            'expires_at'   => $this->expires_at,
            'paid_at'      => $this->paid_at,
            'created_at'   => $this->created_at,
            'items'        => $this->whenLoaded('items', fn() => $this->items->map(fn($i) => [
                'voucher_id'   => $i->voucher_id,
                'voucher_name' => $i->voucher_name,
                'unit_price'   => (float) $i->unit_price,
                'quantity'     => $i->quantity,
                'line_total'   => (float) $i->line_total,
            ])),
            'vouchers'     => $this->whenLoaded(
                'customerVouchers',
                fn() => CustomerVoucherResource::collection($this->customerVouchers)
            ),
        ];
    }
}
