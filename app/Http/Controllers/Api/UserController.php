<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInformation;
use App\Models\CompanyUser;
use App\Models\User;
use Faker\Provider\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    
    public function allCompanyUsers(Request $request)
    {
        $company_users = User::whereIn('role', ['MLRO', 'Analyst'])
            ->with('companyUsers.companyInformation:id,name')
            ->get()
            ->map(function ($user) {
                // Extract company name from first companyUser relation
                $companyName = $user->companyUsers->first()?->companyInformation?->name ?? null;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'company_name' => $companyName,
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'All company users retrieved successfully.',
            'data' => $company_users
        ]);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20|unique:users',
            'role' => 'required|string|in:MLRO,Analyst',
            'company_information_id' => 'required|exists:company_information,id',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'],
            ]);

            $company_user = CompanyUser::create([
                'user_id' => $user->id,
                'company_information_id' => $validated['company_information_id'],
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'User created successfully.',
                'data' => $user->load('companyUsers.companyInformation')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User creation failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!$user || !Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect.',
            ], 401);
        }

        $user->password = bcrypt($validated['new_password']);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
