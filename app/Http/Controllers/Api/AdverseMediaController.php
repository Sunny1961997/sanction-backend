<?php

namespace App\Http\Controllers\Api;

use App\Services\AdverseMediaService;;
// /home/sunny/Documents/sanction-api-2/app/Services/AdverseMediaService.php
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AdverseMediaQueryBuilder;

class AdverseMediaController extends Controller
{
    // public function check(Request $request)
    // {
    //     $request->validate([
    //         'name' => 'required|string',
    //         'mode' => 'nullable|in:exact,adverse,news,social,combined',
    //     ]);

    //     $service = new AdverseMediaService();

    //     $result = $service->search(
    //         $request->name,
    //         $request->mode ?? 'combined',
    //         $request->country
    //     );

    //     return response()->json([
    //         'query_used' => $result['queries']['request'][0]['searchTerms'] ?? null,
    //         'results'    => $result['items'] ?? [],
    //     ]);
    // }
    public function check(Request $request)
{
    $request->validate([
        'name' => 'required|string',
        'mode' => 'nullable|in:exact,adverse,news,social,combined',
    ]);

    $query = AdverseMediaQueryBuilder::build(
        $request->name,
        $request->mode ?? 'combined',
        $request->country
    );

    $service = new AdverseMediaService();
    $result = $service->search(
        $request->name,
        $request->mode ?? 'combined',
        $request->country
    );

    return response()->json([
        'query_used' => $query,                  // ✅ ALWAYS visible
        'google_raw' => $result,                 // ✅ DEBUG (temporary)
        'results'    => $result['items'] ?? [],
    ]);
}
}