<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'AgencyID',
        'UserSysId',
        'customer_id',
        'subtotal',
        'discount',
        'total_amount',
        'payment_status',
        'order_status',
        'notes'
    ];

    // Order Status Constants
    const STATUS_PENDING    = 0;
    const STATUS_CONFIRMED  = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_COMPLETED  = 3;
    const STATUS_CANCELLED  = 4;

    // Payment Status
    const PAYMENT_PENDING = 0;
    const PAYMENT_PAID    = 1;
    const PAYMENT_PARTIAL = 2;
    const PAYMENT_FAILED  = 3;

    /** Relationships */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function logs()
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'AgencyID');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'UserSysId');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    protected $appends = ['order_status_text'];

    public function getOrderStatusTextAttribute()
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

        return $list[$this->order_status] ?? "UNKNOWN";
    }

    /**
     * Update order status + create status log
     */
    public function updateOrderStatus($status, $message, $updatedBy = null)
    {
        $oldStatus = $this->order_status;
        $this->order_status = $status;
        $this->save();

        // Insert log
        OrderStatusLog::create([
            'order_id' => $this->id,
            'status' => $status,
            'message' => $message,
            'updated_by' => $updatedBy ?? $this->UserSysId,
        ]);
    }

    public static function ordersHistoryAPI($User, $perPage, $post = array())
    {
        $responsedata = Order::select(
            'orders.id',
            'orders.customer_id',
            'orders.order_number',
            'orders.subtotal',
            'orders.discount',
            'orders.total_amount',
            'orders.payment_status',
            'orders.order_status',
            'orders.notes',
            'orders.created_at',
        )
            ->where(function ($query) use ($User) {
                $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
                if ($User->UserType == 1) {
                    $query->where('orders.AgencyID', $AgencyID);
                } else {
                    $query->where('orders.AgencyID', $AgencyID)->where('orders.customer_id', $User->id);
                }
            })
            ->with(['customer' => function ($q) {
                $q->select('id', 'name', 'mobile', 'email', 'countrycode')->without('details'); // only required fields
            }])
            ->with(['items' => function ($q) {
                $q->select('order_id', 'product_name', 'qty', 'price'); // only required fields
            }])
            ->orderBy('orders.id', 'DESC')
            ->paginate($perPage);
        return $responsedata;
    }
}
