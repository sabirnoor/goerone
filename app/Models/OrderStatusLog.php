<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusLog extends Model
{
    protected $fillable = [
        'order_id',
        'status',
        'message',
        'updated_by'
    ];
    protected $appends = ['status_text'];
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function getStatusTextAttribute()
    {
        $list = [
            0 => "PENDING",
            1 => "CONFIRMED",
            2 => "PROCESSING",
            3 => "DISPACHED",
            4 => "DELIVERED",
            5 => "CANCELLED",
            6 => "REFUND INITIATED",
            7 => "REFUNDED",
        ];

        return $list[$this->status] ?? "UNKNOWN";
    }

    public static function ordersStatusLogAPI($User, $order_id)
    {
        $responsedata = OrderStatusLog::select(
            'order_status_logs.id',
            'order_status_logs.order_id',
            'order_status_logs.status',
            'order_status_logs.message',
            'order_status_logs.created_at',
            'order_status_logs.updated_by'
        )->where('order_status_logs.order_id', $order_id)
            ->with(['order' => function ($q) {
                $q->select('id', 'order_number', 'notes', 'order_status'); // only required fields
            }])
            ->with(['updatedBy' => function ($q) {
                $q->select('id', 'name', 'mobile', 'email', 'countrycode')->without('details'); // only required fields
            }])
            ->orderBy('order_status_logs.id', 'DESC')
            ->get();
        return $responsedata;
    }
}
