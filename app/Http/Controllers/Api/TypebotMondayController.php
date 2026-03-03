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
            'board_id' => 'nullable|string',
            'group_id' => 'nullable|string',
            'lead_info' => 'nullable|array',
            'project_details' => 'nullable|array',
            'logistics' => 'nullable|array',
            'timeline' => 'nullable|array',
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
        
        $leadInfo = $request->input('lead_info', []);
        $projectDetails = $request->input('project_details', []);
        $logistics = $request->input('logistics', []);
        $timeline = $request->input('timeline', []);

        $email = $leadInfo['email'] ?? null;
        $phone = $leadInfo['phone'] ?? null;

        // Basic validation for email and phone to avoid Monday API errors
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("Invalid email format received: $email. Skipping email column update.");
            $email = null; 
        }

        if ($phone && (strpos($phone, '{{') !== false || strlen($phone) < 5)) {
             Log::warning("Invalid phone format received: $phone. Skipping phone column update.");
             $phoneForColumn = null;
        } else {
             // Clean phone number for Monday column (keep only digits and +)
             $phoneForColumn = $phone ? preg_replace('/[^0-9+]/', '', $phone) : null;
        }

        // Map full_name (Typebot) to name
        $name = ($leadInfo['full_name'] ?? '') ?: ($leadInfo['name'] ?? 'Lead sin nombre');
        $country = $leadInfo['country'] ?? null;
        $company = $leadInfo['company'] ?? null;

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
            $note = $this->generateNoteContent($leadInfo, $projectDetails, $logistics, $timeline);
            
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
                $phoneColumnId => ['phone' => $phoneForColumn, 'countryShortName' => 'US'], // Adjust country default if needed
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
            if (!$phoneForColumn) unset($columnValues[$phoneColumnId]);
            if (!$country) unset($columnValues[$countryColumnId]);
            if (!$company) unset($columnValues[$companyColumnId]);

            $newItem = $this->mondayService->createItem($boardId, $groupId, $name, $columnValues);

            if ($loggingEnabled) {
                Log::info('TypebotMondayController: Create Item Response', ['newItem' => $newItem, 'columnValues' => $columnValues]);
            }

            if ($newItem) {
                // Add note to the new item as well
                $note = $this->generateNoteContent($leadInfo, $projectDetails, $logistics, $timeline);
                $this->mondayService->addUpdate($newItem['id'], $note);

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

    private function generateNoteContent($leadInfo, $projectDetails, $logistics, $timeline)
    {
        return "Lead Info:
" . ($leadInfo['full_name'] ?? '') . "
" . ($leadInfo['email'] ?? '') . "
" . ($leadInfo['phone'] ?? '') . "

Purchase or Rent: " . ($projectDetails['purchase_or_rent'] ?? '') . "
Knows About 16:9 Ratio?: " . ($projectDetails['knows_about_16_9_ratio'] ?? '') . "
Screen Size: " . ($projectDetails['screen_size'] ?? '') . "
Indoor or Outdoor: " . ($projectDetails['indoor_or_outdoor'] ?? '') . "
Size: " . ($projectDetails['size'] ?? '') . "
Need Pixel Advise? " . ($projectDetails['needs_pixel_advise'] ?? '') . "
Pixel Pitch Desired: " . ($projectDetails['pixel_pitch_desired'] ?? '') . "
Checked Nanolite info?: " . ($projectDetails['checked_nanolite_info'] ?? '') . "
Installation method: " . ($projectDetails['installation_method'] ?? '') . "
Budget Range: " . ($projectDetails['budget_range'] ?? '') . "
Intended Purpose: " . ($projectDetails['intended_purpose'] ?? '') . "
Customer Will Pick up?: " . ($logistics['customer_will_pick_up'] ?? '') . "
Shipping State: " . ($logistics['shipping_state'] ?? '') . "
Time Line: " . ($timeline['project_timeline'] ?? '') . "
Time Frame to be contacted: " . ($timeline['preferred_contact_time_frame'] ?? '');
    }
}
