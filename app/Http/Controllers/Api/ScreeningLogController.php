<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScreeningLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScreeningLogController extends Controller
{
    public function index(Request $request)
    {
        $limit = max(1, (int) $request->input('limit', 15));
        $page = max(1, (int) $request->input('offset', 1));
        $skip = ($page - 1) * $limit;

        $user = $request->user();
        $company = $user->company;

        $query = ScreeningLog::query();

        if ($company) {
            // Get all user IDs in this company
            $companyUserIds = $company->users()->pluck('id');
            $query->whereIn('user_id', $companyUserIds);
        } else {
            // Fallback: only current user's logs
            $query->where('user_id', $user->id);
        }

        $query->latest('screening_date');

        // Optional filters
        if ($request->filled('screening_type')) {
            $query->where('screening_type', $request->input('screening_type'));
        }

        if ($request->filled('is_match')) {
            $query->where('is_match', $request->boolean('is_match'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where('search_string', 'like', "%{$search}%");
        }

        if ($request->filled('date_from')) {
            $query->where('screening_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('screening_date', '<=', $request->input('date_to'));
        }

        $total = (clone $query)->count();

        $logs = $query->skip($skip)->take($limit)->get();

        return response()->json([
            'status' => true,
            'message' => 'Screening logs retrieved successfully',
            'data' => [
                'items' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $page,
                'company' => $company ? ['id' => $company->id, 'name' => $company->name] : null,
            ]
        ]);
    }

    /**
     * Store a new screening log
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $company = $user->company;
        Log::info('ScreeningLogController@store called by user id: ' . $user->id . ', company id: ' . ($company?->id ?? 'null'));
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'search_string' => 'required|string',
            'screening_type' => 'required|string|in:individual,entity,vessel',
            'is_match' => 'required|boolean',
        ]);

        $log = ScreeningLog::create([
            'user_id' => $validated['user_id'],
            'company_information_id' => $company?->id,
            'search_string' => $validated['search_string'],
            'screening_type' => $validated['screening_type'],
            'is_match' => $validated['is_match'],
            'screening_date' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Screening log created successfully',
            'data' => $log,
        ], 201);
    }
}
