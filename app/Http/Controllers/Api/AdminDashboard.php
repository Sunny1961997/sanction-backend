<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInformation;
use App\Models\ScreeningLog;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboard extends Controller
{
    public function index()
    {
        $companies = CompanyInformation::count();
        $system_users = User::whereNotIn('role',['Company Admin', 'Analyst', 'MLRO'])->count();
        $active_users = User::where('active', true)->count();
        $screening_logs = ScreeningLog::count();
        return response()->json([
            'status' => true,
            'message' => 'Data fetched successfully',
            'data' => [
                'company_count' => $companies,
                'system_users' => $system_users,
                'screening_logs' => $screening_logs,
                'active_users' => $active_users
            ]
        ]);
    }
}
