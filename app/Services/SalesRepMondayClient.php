<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SalesRepMondayClient
{
    private string $token;

    private string $apiUrl = 'https://api.monday.com/v2';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function request(string $query, array $variables = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->token,
            'Content-Type' => 'application/json',
        ])->acceptJson()->timeout(30)->post($this->apiUrl, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            $body = $response->body();
            $bodyPreview = is_string($body) ? substr($body, 0, 2000) : '';
            throw new \RuntimeException('Monday API HTTP error: '.$response->status().' '.$bodyPreview);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from Monday API');
        }

        if (isset($data['errors'])) {
            throw new \RuntimeException('Monday GraphQL error: '.json_encode($data['errors']));
        }

        return $data['data'] ?? [];
    }
}
