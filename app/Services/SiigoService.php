<?php
namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SiigoService
{
    protected $client;
    public function __construct()
    {
        $this->client = new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public function auth($user, $key)
    {
        try {
            $resp = $this->client->post('https://api.siigo.com/auth', [
                'json' => ['username' => $user, 'access_key' => $key]
            ]);
            $body = json_decode((string)$resp->getBody(), true);
            return $body['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('Siigo auth error: '.$e->getMessage());
            return null;
        }
    }

    /**
     * Post journal to Siigo
     * @return array ['status' => int, 'body' => mixed]
     */
    public function postJournal(array $payload, string $token, ?string $idempotencyKey = null)
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
                'Partner-ID' => 'DutyFreeCol',
                'Accept' => 'application/json'
            ];
            if ($idempotencyKey) $headers['Idempotency-Key'] = $idempotencyKey;

            $resp = $this->client->post('https://api.siigo.com/v1/journals', [
                'headers' => $headers,
                'json' => $payload,
                'http_errors' => false
            ]);

            $status = $resp->getStatusCode();
            $body = json_decode((string)$resp->getBody(), true) ?? (string)$resp->getBody();

            return ['status' => $status, 'body' => $body];
        } catch (\Exception $e) {
            Log::error("Siigo postJournal exception: ".$e->getMessage());
            return ['status' => 0, 'body' => $e->getMessage()];
        }
    }
}
