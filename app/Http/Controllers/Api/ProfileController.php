<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $request->user()->load('companyUsers.companyInformation');
        $currentUserId = $request->user()->id;

        $users = CompanyUser::where('company_information_id', $profile->companyUsers->first()->company_information_id)
            ->whereHas('user', function ($q) use ($currentUserId) {
                $q->where('id', '!=', $currentUserId);
            })
            ->with('user')
            ->get();
        return response()->json([
            'status' => 'success',
            'message' => 'User profile retrieved successfully.',
            'data' => [$profile, 'company_users' => $users],
        ]);

    }
}
