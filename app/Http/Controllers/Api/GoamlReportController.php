<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GoamlReport;
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
        $user_id = $request->user()->id;

        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;

        $skip = ($page - 1) * $limit;

        $query = GoamlReport::with(['customer.individualDetail', 'customer.corporateDetail'])
            ->where('user_id', $user_id)
            ->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                // search in entity_reference (GoamlReport table)
                $q->where('entity_reference', 'like', "%{$search}%")

                    // search in customer type (Customer table)
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('customer_type', 'like', "%{$search}%")

                            // search in individual customer name
                            ->orWhereHas('individualDetail', function ($iq) use ($search) {
                                $iq->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                            })

                            // search in corporate customer name
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
        Log::info('GoamlReportController@store called');
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
            ]);
            Log::info('GoamlReportController@store report before user id: ');
            $user_id = $request->user()->id;
            Log::info('GoamlReportController@store report user id: ' . $user_id);

            $report = GoamlReport::create([
                'user_id' => $user_id,
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

    public function update(Request $request, $id)
    {
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
        ]);

        $report = GoamlReport::findOrFail($id);
        $report->update($validatedData);

        return response()->json($report);
    }
}
