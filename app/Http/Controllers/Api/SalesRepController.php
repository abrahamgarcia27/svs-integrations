<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesRepMondayClient;
use App\Services\SalesRepSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SalesRepController extends Controller
{
    public function webhook(Request $request)
    {
        try {
            $service = $this->buildService();

            return response()->json($service->handle($request->all()));
        } catch (\RuntimeException $e) {
            Log::warning('SalesRep webhook misconfigured', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Log::error('SalesRep webhook failed', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    public function backfill(Request $request)
    {
        $expected = (string) config('services.monday.sales_rep.backfill_token', '');
        if ($expected !== '') {
            $provided = (string) $request->query('token', '');
            if (! hash_equals($expected, $provided)) {
                return response()->json(['status' => 'forbidden'], 403);
            }
        }

        try {
            $service = $this->buildService();
            $dryRun = (string) $request->query('dry_run', '') !== '';

            return response()->json($service->backfill($dryRun));
        } catch (\RuntimeException $e) {
            Log::warning('SalesRep backfill misconfigured', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Log::error('SalesRep backfill failed', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    private function buildService(): SalesRepSyncService
    {
        $config = (array) config('services.monday.sales_rep', []);
        $token = (string) ($config['token'] ?? config('services.monday.token'));

        if ($token === '') {
            throw new \RuntimeException('Missing Monday token for sales-rep integration');
        }

        $client = new SalesRepMondayClient($token);

        return new SalesRepSyncService($client, $config);
    }
}
