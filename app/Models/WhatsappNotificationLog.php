<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappNotificationLog extends Model
{
    protected $table = 'whatsapp_notification_logs';

    protected $fillable = [
        'agency_id',
        'booking_id',
        'template_name',
        'lang_code',
        'phone',
        'recipient_type',
        'send_type',
        'sender_id',
        'source_type',
        'header_format',
        'header_url',
        'status',
        'response_payload',
        'error_message',
        'ip_address',
    ];

    protected $casts = [
        'response_payload' => 'array',
    ];
}