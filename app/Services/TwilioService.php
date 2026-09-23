<?php

namespace App\Services;

// use Twilio\Rest\Client;
use GuzzleHttp\Client;

class TwilioService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
        // $this->client = new Client(
        //     config('services.twilio.sid'),
        //     config('services.twilio.token')
        // );
    }

    public function sendSms($to, $message)
    {
        try {
            $response =  $this->client->get('https://connectexpress.in/api/v3/index.php', [
                'query' => [
                    'method'   => 'sms',
                    'api_key' => env('api_key_textnation'),
                    'sender' => 'CRMOZC',
                    'to'       => $to,
                    'message'  => $message,
                    'format'  => 'php'
                ]
            ]);
            return unserialize((string) $response->getBody());
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
        // return $this->client->messages->create($to, [
        //     'from' => config('services.twilio.from'),
        //     'body' => $message,
        // ]);
    }
}
