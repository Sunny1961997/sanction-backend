<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInformation;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use function Symfony\Component\Clock\now;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = User::where('role', 'Company Admin')
            ->with('companyUsers.companyInformation')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $companies
        ]);
    }
    public function companyUsers($id, Request $request)
    {
        $currentUserId = $request->user()->id;

        $users = CompanyUser::where('company_information_id', $id)
            ->whereHas('user', function ($q) use ($currentUserId) {
                $q->where('id', '!=', $currentUserId);
            })
            ->with('user')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Company users retrieved successfully.',
            'data' => $users
        ]);
    }
    public function store(Request $request)
    {
        Log::info('CompanyController@store called');
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'company_email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20|unique:users',
            'company_name' => 'required|string|max:255',
            'expiration_date' => 'required|date',
            'total_screenings' => 'required|integer',
            'trade_license_number' => 'required|string|max:100',
            // 'reporting_entry_id' => 'required|string|max:100',
            'dob' => 'required|date',
            'passport_number' => 'required|string|max:100',
            'passport_country' => 'required|string|max:100',
            'nationality' => 'required|string|max:100',
            'contact_type' => 'required|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'communication_type' => 'required|string|max:100',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'role' => 'required|string'
        ]);

        DB::beginTransaction();
        try {
            // 1) Create user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'],
            ]);

            // 2) Create company information
            $company_information = CompanyInformation::create([
                'name' => $validated['company_name'],
                'email' => $request['company_email'] ?? $validated['email'],
                'creation_date' => now(),
                'expiration_date' => $validated['expiration_date'],
                'total_screenings' => $validated['total_screenings'],
                'remaining_screenings' => $validated['total_screenings'],
                'trade_license_number' => $validated['trade_license_number'],
                'reporting_entry_id' => $validated['reporting_entry_id'] ?? null,
                'dob' => $validated['dob'],
                'passport_number' => $validated['passport_number'],
                'passport_country' => $validated['passport_country'],
                'nationality' => $validated['nationality'],
                'contact_type' => $validated['contact_type'],
                'communication_type' => $validated['communication_type'],
                'phone_number' => $validated['phone_number'] ?? null,
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'country' => $validated['country'],
            ]);

            // 3) Link user to company (now $company_information->id exists)
            CompanyUser::create([
                'company_information_id' => $company_information->id,
                'user_id' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Company admin created successfully.',
                'data' => $user->load('companyUsers.companyInformation'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Company creation failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create company admin.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
