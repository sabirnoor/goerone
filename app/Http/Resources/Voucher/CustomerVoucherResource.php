<?php

namespace App\Http\Resources\Voucher;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerVoucherResource extends JsonResource
{
    public function toArray($request): array
    {
        $item = $this->orderItem;
        $snap = $item?->voucher_snapshot ?? [];

        return [
            'id'                 => $this->id,
            'voucher_code'       => $this->voucher_code,
            'order_no'           => $this->order?->order_no,
            'voucher_id'         => $this->voucher_id,
            'voucher_name'       => $item?->voucher_name,
            'status'             => $this->effective_status,
            'valid_from'         => $this->valid_from,
            'valid_to'           => $this->valid_to,
            'discount_type'      => $snap['discount_type'] ?? null,
            'discount_value'     => $snap['discount_value'] ?? null,
            'max_discount_value' => $snap['max_discount_value'] ?? null,
            'terms_condition'    => $snap['terms_condition'] ?? null,
            'used_at'            => $this->used_at,
            'voucher'            => $this->voucher,
        ];
    }
}
