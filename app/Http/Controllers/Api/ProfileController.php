<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function accountStats(Request $request)
    {
        try {
            $user = $request->user();
            $company = $user->company;

            // Get company user IDs for filtering
            $companyUserIds = [];
            if ($company) {
                $companyUserIds = $company->users()->pluck('id')->toArray();
            } else {
                $companyUserIds = [$user->id];
            }

            // Filter customers by company users
            $customersQuery = Customer::whereIn('user_id', $companyUserIds);

            // 1. User Type Distribution (Individual vs Corporate)
            $userTypeDistribution = [
                'individual' => (clone $customersQuery)->where('customer_type', 'individual')->count(),
                'corporate' => (clone $customersQuery)->where('customer_type', 'corporate')->count(),
            ];

            // 2. Risk Level Distribution (1-5)
            $riskLevelDistribution = [
                'low' => (clone $customersQuery)->where('risk_level', 1)->count(),           // Risk 1
                'low_medium' => (clone $customersQuery)->where('risk_level', 2)->count(),    // Risk 2
                'medium' => (clone $customersQuery)->where('risk_level', 3)->count(),        // Risk 3
                'medium_high' => (clone $customersQuery)->where('risk_level', 4)->count(),   // Risk 4
                'high' => (clone $customersQuery)->where('risk_level', 5)->count(),          // Risk 5
            ];

            // 3. Onboarding Status (assuming you have a 'status' column in customers table)
            // If not, you may need to add a migration for this
            $onboardingStatus = [
                'onboarded' => (clone $customersQuery)->where('status', 'onboarded')->count(),
                'in_review' => (clone $customersQuery)->where('status', 'in_review')->count(),
                'false_match' => (clone $customersQuery)->where('status', 'false_match')->count(),
                'rejected' => (clone $customersQuery)->where('status', 'rejected')->count(),
                'alerted' => (clone $customersQuery)->where('status', 'alerted')->count(),
            ];

            // 4. Risk Assessment (High Risk >= 4)
            $riskAssessment = [
                'high_risk_count' => (clone $customersQuery)->where('risk_level', '>=', 4)->count(),
                'total_count' => (clone $customersQuery)->count(),
            ];

            // 5. Ongoing Monitoring Requirements
            // Customers requiring special monitoring (e.g., PEP, high-risk countries, adverse news)
            $ongoingMonitoring = Customer::whereIn('user_id', $companyUserIds)
                ->where(function ($q) {
                    $q->whereHas('individualDetail', function ($iq) {
                        $iq->where('is_pep', true)
                        ->orWhere('adverse_news', true);
                    })
                    ->orWhereHas('corporateDetail', function ($cq) {
                        $cq->where('is_entity_having_adverse_news', true);
                    })
                    ->orWhere('risk_level', '>=', 4);
                })
                ->count();

            return response()->json([
                'status' => true,
                'message' => 'Account statistics retrieved successfully',
                'data' => [
                    'user_type_distribution' => $userTypeDistribution,
                    'risk_level_distribution' => $riskLevelDistribution,
                    'onboarding_status' => $onboardingStatus,
                    'risk_assessment' => $riskAssessment,
                    'ongoing_monitoring_count' => $ongoingMonitoring,
                    'company_info' => $company ? [
                        'id' => $company->id,
                        'name' => $company->name,
                    ] : null,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Account stats retrieval failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve account statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
