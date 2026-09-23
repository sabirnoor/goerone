<?php
// app/Services/ElasticEmailService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ElasticEmailService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('ELASTIC_EMAIL_API_KEY'); // Store in .env
        $this->baseUrl = 'https://api.elasticemail.com/v2';
    }

    // 1. Add domain
    public function addDomain($domain)
    {
        $response = Http::get("{$this->baseUrl}/domain/add", [
            'apikey' => $this->apiKey,
            'domain' => $domain,
        ]);

        return $response->json();
    }

    // 2. Get domain DNS details (SPF/DKIM)
    public function getDomainDetails($domain)
    {
        $response = Http::get("{$this->baseUrl}/domain/load", [
            'apikey' => $this->apiKey,
            'domain' => $domain,
        ]);

        return $response->json();
    }

    // 3. List all domains
    public function listDomains()
    {
        $response = Http::get("{$this->baseUrl}/domain/list", [
            'apikey' => $this->apiKey,
        ]);

        return $response->json();
    }
}
