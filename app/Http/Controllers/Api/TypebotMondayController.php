<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MondayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TypebotMondayController extends Controller
{
    protected $mondayService;

    public function __construct(MondayService $mondayService)
    {
        $this->mondayService = $mondayService;
    }

    public function upsert(Request $request)
    {
        $request->validate([
            'board_id' => 'nullable|string', // Optional now, defaults to config
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'full_name' => 'nullable|string',
            'name' => 'nullable|string',
            'country' => 'nullable|string',
            'company' => 'nullable|string',
            'group_id' => 'nullable|string', // Optional: ID of the group for new leads
        ]);

        // Get config values
        $configBoardId = config('services.monday.leads.board_id');
        $configGroupId = config('services.monday.leads.group_id');
        $emailColumnId = config('services.monday.leads.email_column', 'email');
        $phoneColumnId = config('services.monday.leads.phone_column', 'phone');
        $nameColumnId = config('services.monday.leads.name_column', 'name');
        $sourceColumnId = config('services.monday.leads.source_column', 'dup__of_source_mkn2km3h');
        $countryColumnId = config('services.monday.leads.country_column', 'country');
        $companyColumnId = config('services.monday.leads.company_column', 'company');
        $loggingEnabled = config('services.monday.leads.logging_enabled', false);

        if ($loggingEnabled) {
            Log::info('TypebotMondayController: Incoming Request', $request->all());
        }

        // Determine values to use (Request > Config)
        $boardId = $request->input('board_id') ?? $configBoardId;
        $groupId = $request->input('group_id') ?? $configGroupId;
        
        $email = $request->input('email');
        $phone = $request->input('phone');
        // Map full_name (Typebot) to name
        $name = $request->input('full_name') ?: ($request->input('name') ?: 'Lead sin nombre');
        $country = $request->input('country');
        $company = $request->input('company');

        if (!$boardId) {
            return response()->json(['status' => 'error', 'message' => 'Board ID is required (env or request)'], 400);
        }

        $existingItem = null;

        // 1. Search by Email
        if ($email) {
            $existingItem = $this->mondayService->searchItemByColumn($boardId, $emailColumnId, $email);
        }

        // 2. Search by Phone if not found
        if (!$existingItem && $phone) {
            $existingItem = $this->mondayService->searchItemByColumn($boardId, $phoneColumnId, $phone);
        }

        if ($existingItem) {
            // Scenario A: Update existing item
            $note = "Actualización recibida desde Typebot.\nFecha: " . now()->toDateTimeString();
            
            // You can customize the note content here
            $this->mondayService->addUpdate($existingItem['id'], $note);

            return response()->json([
                'status' => 'success',
                'action' => 'updated',
                'item_id' => $existingItem['id'],
                'message' => 'Lead already exists. Note added.'
            ]);
        } else {
            // Scenario B: Create new item
            $columnValues = [
                // Map standard columns using configured IDs
                $emailColumnId => ['email' => $email, 'text' => $email],
                $phoneColumnId => ['phone' => $phone, 'countryShortName' => 'US'], // Adjust country default if needed
                $countryColumnId => $country,
                $companyColumnId => $company,
                
                // Set Source column to "Typebot"
                // Assuming it's a Status column. If Dropdown, structure might differ.
                $sourceColumnId => ['label' => 'Typebot'] 
            ];

            // If name column is explicitly configured and not "name" (item name), add it to columns
            // But usually item_name is separate. We can also duplicate it if needed.
            // For now, if nameColumnId is not 'name', we add it.
            if ($nameColumnId !== 'name') {
                $columnValues[$nameColumnId] = $name;
            }

            // Filter out null values
            if (!$email) unset($columnValues[$emailColumnId]);
            if (!$phone) unset($columnValues[$phoneColumnId]);
            if (!$country) unset($columnValues[$countryColumnId]);
            if (!$company) unset($columnValues[$companyColumnId]);

            $newItem = $this->mondayService->createItem($boardId, $groupId, $name, $columnValues);

            if ($loggingEnabled) {
                Log::info('TypebotMondayController: Create Item Response', ['newItem' => $newItem, 'columnValues' => $columnValues]);
            }

            if ($newItem) {
                return response()->json([
                    'status' => 'success',
                    'action' => 'created',
                    'item_id' => $newItem['id'],
                    'message' => 'New lead created in Monday.'
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create item in Monday.'
                ], 500);
            }
        }
    }
}
