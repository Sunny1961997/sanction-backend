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

        $userId = $request->user()->id;
        Log::info("Fetching screening logs for user_id: {$userId}");

        $query = ScreeningLog::where('user_id', $userId)
            ->with('user')
            ->latest('screening_date');

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
            ]
        ]);
    }

    /**
     * Store a new screening log
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'search_string' => 'required|string',
            'screening_type' => 'required|string|in:individual,entity,vessel',
            'is_match' => 'required|boolean',
        ]);

        $log = ScreeningLog::create([
            'user_id' => $validated['user_id'],
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
