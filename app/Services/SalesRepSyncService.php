<?php

namespace App\Services;

class SalesRepSyncService
{
    private SalesRepMondayClient $client;

    private int $leadsBoardId;

    private int $oppsBoardId;

    private string $leadOwnerColId;

    private string $dealOwnerColId;

    private string $connectBoardsColId;

    private string $oppsEmailColId;

    private string $leadEmailColId;

    public function __construct(SalesRepMondayClient $client, array $config)
    {
        $this->client = $client;
        $this->leadsBoardId = (int) ($config['leads_board_id'] ?? 0);
        $this->oppsBoardId = (int) ($config['opps_board_id'] ?? 0);
        $this->leadOwnerColId = (string) ($config['lead_owner_col_id'] ?? 'lead_owner');
        $this->dealOwnerColId = (string) ($config['deal_owner_col_id'] ?? 'deal_owner');
        $this->connectBoardsColId = (string) ($config['connect_boards_col_id'] ?? 'link_to_leads__1');
        $this->oppsEmailColId = (string) ($config['opps_email_col_id'] ?? 'email');
        $this->leadEmailColId = (string) ($config['lead_email_col_id'] ?? 'lead_email');
    }

    public function handle(array $payload): array
    {
        if (isset($payload['challenge'])) {
            return ['challenge' => $payload['challenge']];
        }

        $event = $payload['event'] ?? [];
        $boardId = $event['boardId'] ?? null;
        $itemId = $event['pulseId'] ?? ($event['itemId'] ?? null);

        if (! $boardId || ! $itemId) {
            return ['status' => 'ignored'];
        }

        if ((int) $boardId !== $this->oppsBoardId) {
            return ['status' => 'ignored'];
        }

        $personId = $this->determinePersonId((int) $itemId);
        if (! $personId) {
            return ['status' => 'no_person'];
        }

        $this->assignPeople((int) $itemId, $personId);

        return ['status' => 'ok'];
    }

    public function backfill(bool $dryRun = false): array
    {
        $this->assertConfigured();

        $peopleCols = $this->peopleColumns();
        $colsForQuery = array_values(array_unique(array_merge($peopleCols, [$this->connectBoardsColId, $this->dealOwnerColId, $this->oppsEmailColId])));

        $cursor = null;
        $scanned = 0;
        $updated = 0;
        $wouldUpdate = 0;

        while (true) {
            $q = 'query ($board: [ID!], $cursor: String, $cols: [String!]) { boards(ids: $board) { items_page(cursor: $cursor) { cursor items { id column_values(ids: $cols) { id type ... on PeopleValue { persons_and_teams { id kind } } ... on BoardRelationValue { linked_item_ids } ... on EmailValue { email } } } } } }';
            $data = $this->client->request($q, ['board' => [$this->oppsBoardId], 'cursor' => $cursor, 'cols' => $colsForQuery]);
            $page = $data['boards'][0]['items_page'] ?? null;

            if (! $page || empty($page['items'])) {
                break;
            }

            foreach ($page['items'] as $item) {
                $scanned++;
                $cv = $item['column_values'] ?? [];

                $connectedLeadId = null;
                $dealOwnerEmpty = true;
                $oppsEmail = null;

                foreach ($cv as $c) {
                    if (($c['id'] ?? null) === $this->connectBoardsColId && isset($c['linked_item_ids'][0])) {
                        $connectedLeadId = (int) $c['linked_item_ids'][0];
                    }
                    if (($c['id'] ?? null) === $this->dealOwnerColId) {
                        $dealOwnerEmpty = empty($c['persons_and_teams']);
                    }
                    if (($c['id'] ?? null) === $this->oppsEmailColId) {
                        $oppsEmail = $c['email'] ?? null;
                    }
                }

                if (! $dealOwnerEmpty) {
                    continue;
                }

                $personId = null;
                if ($connectedLeadId) {
                    $personId = $this->resolveLeadOwnerByItem($connectedLeadId);
                }

                if (! $personId) {
                    $personId = $this->resolveLeadOwnerByOppsEmail($oppsEmail);
                }

                if (! $personId) {
                    continue;
                }

                if ($dryRun) {
                    $wouldUpdate++;
                } else {
                    $this->assignPeople((int) $item['id'], $personId);
                    $updated++;
                }
            }

            $cursor = $page['cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return [
            'status' => 'ok',
            'scanned' => $scanned,
            'updated' => $updated,
            'would_update' => $wouldUpdate,
            'dry_run' => $dryRun,
            'board_id' => $this->oppsBoardId,
        ];
    }

    private function assertConfigured(): void
    {
        if ($this->oppsBoardId <= 0) {
            throw new \RuntimeException('MONDAY_SALES_REP_OPPS_BOARD_ID missing');
        }
        if ($this->leadsBoardId <= 0) {
            throw new \RuntimeException('MONDAY_SALES_REP_LEADS_BOARD_ID missing');
        }
        if ($this->connectBoardsColId === '') {
            throw new \RuntimeException('MONDAY_SALES_REP_CONNECT_BOARDS_COL_ID missing');
        }
        if ($this->dealOwnerColId === '') {
            throw new \RuntimeException('MONDAY_SALES_REP_DEAL_OWNER_COL_ID missing');
        }
        if ($this->leadOwnerColId === '') {
            throw new \RuntimeException('MONDAY_SALES_REP_LEAD_OWNER_COL_ID missing');
        }
    }

    private function peopleColumns(): array
    {
        $cols = $this->client->request(
            'query ($board: [ID!]) { boards(ids: $board) { columns { id type title } } }',
            ['board' => [$this->oppsBoardId]]
        );

        $columns = $cols['boards'][0]['columns'] ?? [];
        $ids = [];
        foreach ($columns as $col) {
            if (($col['type'] ?? null) !== 'people') {
                continue;
            }
            $id = (string) ($col['id'] ?? '');
            $title = (string) ($col['title'] ?? '');
            if ($id === $this->dealOwnerColId || stripos($title, 'Sales Rep') !== false) {
                $ids[] = $id;
            }
        }

        if (empty($ids)) {
            $ids[] = $this->dealOwnerColId;
        }

        return $ids;
    }

    private function determinePersonId(int $opportunityItemId): ?int
    {
        $q = 'query ($id: [ID!], $conn: String!, $owner: String!, $email: String!) { items(ids: $id) { id column_values(ids: [$conn,$owner,$email]) { id type ... on BoardRelationValue { linked_item_ids } ... on PeopleValue { persons_and_teams { id kind } } ... on EmailValue { email } } } }';
        $d = $this->client->request($q, ['id' => [$opportunityItemId], 'conn' => $this->connectBoardsColId, 'owner' => $this->dealOwnerColId, 'email' => $this->oppsEmailColId]);
        $items = $d['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $cv = $items[0]['column_values'] ?? [];
        $linkedLeadId = null;
        $oppsEmail = null;
        foreach ($cv as $c) {
            if (($c['id'] ?? null) === $this->connectBoardsColId && isset($c['linked_item_ids'][0])) {
                $linkedLeadId = (int) $c['linked_item_ids'][0];
                break;
            }
            if (($c['id'] ?? null) === $this->oppsEmailColId) {
                $oppsEmail = $c['email'] ?? null;
            }
        }

        if ($linkedLeadId) {
            $pid = $this->resolveLeadOwnerByItem($linkedLeadId);
            if ($pid) {
                return $pid;
            }
        }

        $pidByEmail = $this->resolveLeadOwnerByOppsEmail($oppsEmail);
        if ($pidByEmail) {
            return $pidByEmail;
        }

        foreach ($cv as $c) {
            if (($c['id'] ?? null) === $this->dealOwnerColId && ! empty($c['persons_and_teams'])) {
                return (int) $c['persons_and_teams'][0]['id'];
            }
        }

        return null;
    }

    private function resolveLeadOwnerByOppsEmail($oppsEmail): ?int
    {
        if (! is_string($oppsEmail) || $oppsEmail === '') {
            return null;
        }

        $leadId = $this->findLeadIdByEmail($oppsEmail);
        if (! $leadId) {
            return null;
        }

        return $this->resolveLeadOwnerByItem($leadId);
    }

    private function findLeadIdByEmail(string $email): ?int
    {
        if ($this->leadEmailColId === '') {
            return null;
        }

        $q = 'query ($boardId: ID!, $columnId: String!, $value: String!) { items_page_by_column_values (limit: 1, board_id: $boardId, columns: [{column_id: $columnId, column_values: [$value]}]) { items { id } } }';
        $data = $this->client->request($q, ['boardId' => (string) $this->leadsBoardId, 'columnId' => $this->leadEmailColId, 'value' => $email]);
        $itemId = $data['items_page_by_column_values']['items'][0]['id'] ?? null;

        if (! $itemId) {
            return null;
        }

        return (int) $itemId;
    }

    private function resolveLeadOwnerByItem(int $leadItemId): ?int
    {
        $q1 = 'query ($ids: [ID!], $lead: String!) { items(ids: $ids) { id column_values(ids: [$lead]) { ... on PeopleValue { persons_and_teams { id kind } } } } }';
        $d1 = $this->client->request($q1, ['ids' => [$leadItemId], 'lead' => $this->leadOwnerColId]);
        $pv1 = $d1['items'][0]['column_values'][0]['persons_and_teams'] ?? [];
        if (! empty($pv1)) {
            return (int) $pv1[0]['id'];
        }

        $q2 = 'query ($ids: [ID!]) { items(ids: $ids) { id column_values(types: people) { id column { title } ... on PeopleValue { persons_and_teams { id kind } } } } }';
        $d2 = $this->client->request($q2, ['ids' => [$leadItemId]]);
        $cvs = $d2['items'][0]['column_values'] ?? [];

        foreach ($cvs as $c) {
            $vals = $c['persons_and_teams'] ?? [];
            $title = (string) ($c['column']['title'] ?? '');
            $id = (string) ($c['id'] ?? '');
            if (! empty($vals) && (stripos($title, 'Sales Rep') !== false || $id === $this->leadOwnerColId)) {
                return (int) $vals[0]['id'];
            }
        }

        foreach ($cvs as $c) {
            $vals = $c['persons_and_teams'] ?? [];
            if (! empty($vals)) {
                return (int) $vals[0]['id'];
            }
        }

        return null;
    }

    private function assignPeople(int $itemId, int $personId): void
    {
        $peopleCols = $this->peopleColumns();
        $columnsToUpdate = [];
        foreach ($peopleCols as $cid) {
            $columnsToUpdate[$cid] = ['personsAndTeams' => [['id' => $personId, 'kind' => 'person']]];
        }

        $mutation = 'mutation ($item_id: ID!, $board_id: ID!, $values: JSON!) { change_multiple_column_values(item_id: $item_id, board_id: $board_id, column_values: $values) { id } }';
        $this->client->request($mutation, [
            'item_id' => $itemId,
            'board_id' => $this->oppsBoardId,
            'values' => json_encode($columnsToUpdate),
        ]);
    }
}
