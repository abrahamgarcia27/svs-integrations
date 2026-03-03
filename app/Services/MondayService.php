<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MondayService
{
    protected $apiKey;
    protected $apiUrl = 'https://api.monday.com/v2';

    public function __construct()
    {
        $this->apiKey = config('services.monday.token');
    }

    /**
     * Search for an item in a board by column value (e.g. email or phone)
     */
    public function searchItemByColumn($boardId, $columnId, $value)
    {
        $query = 'query ($boardId: ID!, $columnId: String!, $value: String!) {
            items_page_by_column_values (limit: 1, board_id: $boardId, columns: [{column_id: $columnId, column_values: [$value]}]) {
                items {
                    id
                    name
                }
            }
        }';

        $response = $this->makeRequest($query, [
            'boardId' => $boardId,
            'columnId' => $columnId,
            'value' => $value
        ]);

        if (isset($response['data']['items_page_by_column_values']['items'][0])) {
            return $response['data']['items_page_by_column_values']['items'][0];
        }

        return null;
    }

    /**
     * Create a new item in the board
     */
    public function createItem($boardId, $groupId, $itemName, $columnValues = [])
    {
        $query = 'mutation ($boardId: ID!, $groupId: String!, $itemName: String!, $columnValues: JSON!) {
            create_item (board_id: $boardId, group_id: $groupId, item_name: $itemName, column_values: $columnValues) {
                id
            }
        }';

        $response = $this->makeRequest($query, [
            'boardId' => $boardId,
            'groupId' => $groupId,
            'itemName' => $itemName,
            'columnValues' => json_encode($columnValues)
        ]);

        return $response['data']['create_item'] ?? null;
    }

    /**
     * Add an update (note) to an item
     */
    public function addUpdate($itemId, $body)
    {
        $query = 'mutation ($itemId: ID!, $body: String!) {
            create_update (item_id: $itemId, body: $body) {
                id
            }
        }';

        return $this->makeRequest($query, [
            'itemId' => $itemId,
            'body' => $body
        ]);
    }

    protected function makeRequest($query, $variables = [])
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            Log::error('Monday API Error', ['response' => $response->body()]);
            return null;
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            Log::error('Monday GraphQL Error', ['errors' => $data['errors'], 'variables' => $variables]);
            return null;
        }

        return $data;
    }
}
