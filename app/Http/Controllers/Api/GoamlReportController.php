<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GoamlReport;
use App\Models\ScreeningLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpParser\Node\Stmt\TryCatch;

class GoamlReportController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $page = (int) $request->input('offset', 1);
        $search = trim((string) $request->input('search', ''));
        
        $user = $request->user();
        $company = $user->company; // Now this works!

        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;

        $skip = ($page - 1) * $limit;

        // Filter by company: get all reports from users in the same company
        $query = GoamlReport::with(['customer.individualDetail', 'customer.corporateDetail']);

        if ($company) {
            // Get all user IDs in this company
            $companyUserIds = $company->users()->pluck('id');
            $query->whereIn('user_id', $companyUserIds);
        } else {
            // Fallback: only current user's reports
            $query->where('user_id', $user->id);
        }

        $query->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('entity_reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('customer_type', 'like', "%{$search}%")
                            ->orWhereHas('individualDetail', function ($iq) use ($search) {
                                $iq->where('first_name', 'like', "%{$search}%")
                                   ->orWhere('last_name', 'like', "%{$search}%");
                            })
                            ->orWhereHas('corporateDetail', function ($coq) use ($search) {
                                $coq->where('company_name', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $total = (clone $query)->count();

        $reports = $query->skip($skip)->take($limit)->get();

        foreach ($reports as $report) {
            $report->customer_name = null;
            if ($report->customer) {
                if ($report->customer->customer_type === 'individual' && $report->customer->individualDetail) {
                    $report->customer_name = $report->customer->individualDetail->first_name . ' ' . $report->customer->individualDetail->last_name;
                } elseif ($report->customer->customer_type === 'corporate' && $report->customer->corporateDetail) {
                    $report->customer_name = $report->customer->corporateDetail->company_name;
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'GOAML reports retrieved successfully',
            'data' => [
                'reports' => $reports,
                'total' => $total,
                'limit' => $limit,
                'offset' => $page,
                'company' => $company ? ['id' => $company->id, 'name' => $company->name] : null,
            ]
        ]);
    }
    public function show($id)
    {
        $report = GoamlReport::with(['customer.individualDetail', 'customer.corporateDetail'])
            ->findOrFail($id);

        $report->customer_name = null;
        if ($report->customer) {
            if ($report->customer->customer_type === 'individual' && $report->customer->individualDetail) {
                $report->customer_name = $report->customer->individualDetail->first_name . ' ' . $report->customer->individualDetail->last_name;
            } elseif ($report->customer->customer_type === 'corporate' && $report->customer->corporateDetail) {
                $report->customer_name = $report->customer->corporateDetail->company_name;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'GOAML report retrieved successfully',
            'data' => [
                'report' => $report
            ]
        ]);
    }

    public function meta(){
        $customers = Customer::with([
            'individualDetail:id,customer_id,first_name,last_name', 
            'corporateDetail:id,customer_id,company_name'
        ])
        ->select('id', 'customer_type')
        ->get();
        foreach($customers as $customer){
            if($customer->customer_type == 'individual'){
                $customer->name = $customer->individualDetail->first_name . ' ' . $customer->individualDetail->last_name;
            }
            else{
                $customer->name = $customer->corporateDetail->company_name;
            }
        }
        $countriesPath = resource_path('json/countries.json');
        if (file_exists($countriesPath)) {
            $countries = json_decode(file_get_contents($countriesPath), true);
        }
        return response()->json([
            'status' => true,
            'message' => 'Metadata retrieved successfully',
            'data' => [
                'customers' => $customers,
                'countries' => $countries ?? [],
            ]
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'entity_reference' => 'required|string',
                'transaction_type' => 'required|string',
                'comments' => 'required|string',
                'customer_id' => 'required|integer',
                'item_type' => 'required|string',
                'item_make' => 'required|string',
                'description' => 'required|string',
                'disposed_value' => 'required|numeric',
                'status_comments' => 'required|string',
                'estimated_value' => 'required|numeric',
                'currency_code' => 'required|string',
                'status' => 'sometimes|string',
            ]);
            // $user_id = $request->user()->id;
            $user = $request->user();
            $company = $user->company;
            $status = '';
            if($user->role == 'Analyst'){
                $status = 'pending';
            }
            else{
                $status = 'submitted';
            }
            Log::info('GoamlReportController@store report user id: ' . $user->id . ', company id: ' . $company->id);

            $report = GoamlReport::create([
                'user_id' => $user->id,
                'company_information_id' => $company->id,
                'entity_reference' => $validatedData['entity_reference'],
                'transaction_type' => $validatedData['transaction_type'],
                'comments' => $validatedData['comments'],
                'customer_id' => $validatedData['customer_id'],
                'item_type' => $validatedData['item_type'],
                'item_make' => $validatedData['item_make'],
                'description' => $validatedData['description'],
                'disposed_value' => $validatedData['disposed_value'],
                'status_comments' => $validatedData['status_comments'],
                'estimated_value' => $validatedData['estimated_value'],
                'currency_code' => $validatedData['currency_code'],
                'status' => $status,
            ]);
            Log::info('GoamlReportController@store report created: ' . $report->id);

            return response()->json([
                'status' => true,
                'message' => 'GOAML report created successfully',
                'data' => [
                    'report' => $report
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('GoamlReportController@store error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create GOAML report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // public function update(Request $request, $id)
    // {
    //     $validatedData = $request->validate([
    //         'entity_reference' => 'required|string',
    //         'transaction_type' => 'required|string',
    //         'comments' => 'required|string',
    //         'customer_id' => 'required|integer',
    //         'item_type' => 'required|string',
    //         'item_make' => 'required|string',
    //         'description' => 'required|string',
    //         'disposed_value' => 'required|numeric',
    //         'status_comments' => 'required|string',
    //         'estimated_value' => 'required|numeric',
    //         'currency_code' => 'required|string',
    //     ]);

    //     $report = GoamlReport::findOrFail($id);
    //     $report->update($validatedData);

    //     return response()->json($report);
    // }
    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'entity_reference' => 'sometimes|required|string',
                'transaction_type' => 'sometimes|required|string',
                'comments' => 'sometimes|required|string',
                'customer_id' => 'sometimes|required|integer',
                'item_type' => 'sometimes|required|string',
                'item_make' => 'sometimes|required|string',
                'description' => 'sometimes|required|string',
                'disposed_value' => 'sometimes|required|numeric',
                'status_comments' => 'sometimes|required|string',
                'estimated_value' => 'sometimes|required|numeric',
                'currency_code' => 'sometimes|required|string',
                'status' => 'sometimes|string|in:pending,submitted,approved,rejected',
            ]);

            $user = $request->user();
            $company = $user->company;
            Log::info('GoamlReportController@update report update called by user id: ' . $company->users()->pluck('id'));
            Log::info('GoamlReportController@update report update called by user id: ' . $user->id );
            Log::info('id: ' . $id );
            // Find report and verify ownership
            $report = GoamlReport::where('id', $id)
                ->where(function ($q) use ($user, $company) {
                    if ($company) {
                        Log::info('Enter company check');
                        // Allow access if user belongs to the same company
                        $companyUserIds = $company->users()->pluck('id');
                        $q->whereIn('user_id', $companyUserIds);
                    } else {
                        // Fallback: only current user's reports
                        $q->where('user_id', $user->id);
                    }
                })
                ->first();
            Log::info("Report fetched: " . ($report ? $report->id : 'null'));

            if (!$report) {
                return response()->json([
                    'status' => false,
                    'message' => 'GOAML report not found or unauthorized access',
                ], 404);
            }
            // $report = GoamlReport::findOrFail($id);
            // if (!$report) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'GOAML report not found or unauthorized access',
            //     ], 404);
            // }
            // Update only provided fields
            $report->update(array_filter($validatedData, fn($v) => $v !== null));

            Log::info('GoamlReportController@update report updated: ' . $report->id . ' by user: ' . $user->id);

            return response()->json([
                'status' => true,
                'message' => 'GOAML report updated successfully',
                'data' => [
                    'report' => $report
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('GoamlReportController@update error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update GOAML report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $company = $user->company;

            // Find report and verify ownership
            $report = GoamlReport::where('id', $id)
                ->where(function ($q) use ($user, $company) {
                    if ($company) {
                        // Allow access if user belongs to the same company
                        $companyUserIds = $company->users()->pluck('id');
                        $q->whereIn('user_id', $companyUserIds);
                    } else {
                        // Fallback: only current user's reports
                        $q->where('user_id', $user->id);
                    }
                })
                ->first();

            if (!$report) {
                return response()->json([
                    'status' => false,
                    'message' => 'GOAML report not found or unauthorized access',
                ], 404);
            }

            // Optional: Prevent deletion of submitted/approved reports
            if (in_array($report->status, ['submitted', 'approved'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete a report with status: ' . $report->status,
                ], 403);
            }

            $reportId = $report->id;
            $report->delete();

            Log::info('GoamlReportController@destroy report deleted: ' . $reportId . ' by user: ' . $user->id);

            return response()->json([
                'status' => true,
                'message' => 'GOAML report deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('GoamlReportController@destroy error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete GOAML report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
