<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\IndividualCustomerDetail;
use App\Models\CorporateCustomerDetail;
use App\Models\CustomerDocument;
use App\Models\CountryOperation;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerOnboardingController extends Controller
{
    /**
     * Return meta data for the onboarding form (countries and products)
     */
    public function meta(Request $request)
    {
        $countries = [];
        $countriesPath = resource_path('json/countries.json');

        if (file_exists($countriesPath)) {
            $countries = json_decode(file_get_contents($countriesPath), true);
        }

        $products = Product::select('id','name','sku')->get();

        return response()->json([
            'status' => true,
            'message' => 'Meta data retrieved successfully',
            'data' => [
                'countries' => $countries,
                'products' => $products,
            ],
        ]);
    }

    // New: list customers with limit/offset (offset is 1-based page number) and relations
    public function index(Request $request)
    {
        $limit = (int) $request->get('limit', 15);
        $page = (int) $request->get('offset', 1);
        if ($page < 1) {
            $page = 1;
        }

        $user = $request->user();

        // Determine which user_id to filter by
        $filterUserId = $this->getFilterUserId($user);

        if (!$filterUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Optimize query: select only needed columns from relations
        $query = Customer::with([
            'individualDetail:id,customer_id,first_name,last_name,email,country',
            'corporateDetail:id,customer_id,company_name,email,country_incorporated',
        ])
        ->where('user_id', $filterUserId);

        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->get('customer_type'));
        }

        if ($request->filled('search')) {
            $s = $request->get('search');
            $query->where(function ($q) use ($s) {
                $q->where('remarks', 'like', "%{$s}%")
                    ->orWhereHas('individualDetail', function ($q2) use ($s) {
                        $q2->where('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%")
                        ->orWhere('contact_no', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");
                    })
                    ->orWhereHas('corporateDetail', function ($q2) use ($s) {
                        $q2->where('company_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");
                    });
            });
        }

        $total = (clone $query)->count();

        $skip = ($page - 1) * $limit;

        $customers = $query->orderBy('created_at', 'desc')
            ->skip($skip)
            ->take($limit)
            ->get();

        $mappedCustomers = $customers->map(function ($customer) {
            $name = null;
            $email = null;
            $country = null;

            if ($customer->customer_type === 'individual' && $customer->individualDetail) {
                $name = $customer->individualDetail->first_name . ' ' . $customer->individualDetail->last_name;
                $email = $customer->individualDetail->email;
                $country = $customer->individualDetail->country;
            } elseif ($customer->customer_type === 'corporate' && $customer->corporateDetail) {
                $name = $customer->corporateDetail->company_name;
                $email = $customer->corporateDetail->email;
                $country = $customer->corporateDetail->country_incorporated;
            }

            if ($customer->countryOperations->isNotEmpty()) {
                $country = $customer->countryOperations->first()->country;
            }

            return [
                'id' => $customer->id,
                'name' => $name,
                'country' => $country,
                'email' => $email,
                'customer_type' => $customer->customer_type,
                'status' => $customer->status ?? 'Active',
                'created_at' => $customer->created_at->toDateTimeString(),
                'risk_level' => $customer->risk_level,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Customers retrieved successfully',
            'data' => [
                'items' => $mappedCustomers,
                'total' => $total,
                'limit' => $limit,
                'offset' => $page,
            ],
        ]);
    }
    private function getFilterUserId($user): ?int
    {
        if ($user->role === 'Company Admin') {
            return $user->id;
        }

        // Company User: find the admin in the same company
        $companyUser = \App\Models\CompanyUser::where('user_id', $user->id)->first();

        if (!$companyUser) {
            return null;
        }

        // Find the Company Admin in the same company
        $adminUser = \App\Models\CompanyUser::where('company_information_id', $companyUser->company_information_id)
            ->whereHas('user', function ($q) {
                $q->where('role', 'Company Admin');
            })
            ->with('user')
            ->first();

        return $adminUser?->user_id ?? null;
    }

    public function shortDataCustomers(Request $request){
        $user_id = $request->user()->id;
        $customer_type = $request->input('customer_type', null);
        $query = Customer::with([
            'individualDetail:id,customer_id,first_name,last_name', 
            'corporateDetail:id,customer_id,company_name'
        ])
        ->where('user_id', $user_id)
        ->where('customer_type', $customer_type)
        ->select('id', 'customer_type');

        if($customer_type == 'individual'){
            $query->where('customer_type', 'individual');
        }
        else if($customer_type == 'corporate'){
            $query->where('customer_type', 'corporate');
        }

        $customers = $query->get();
        foreach($customers as $customer){
            if(strtolower($customer->customer_type) == 'individual'){
                $customer->name = $customer->individualDetail->first_name . ' ' . $customer->individualDetail->last_name;
            }
            else{
                $customer->name = $customer->corporateDetail->company_name;
            }
        }
        return response()->json([
            'status' => true,
            'message' => 'Customers retrieved successfully',
            'data' => $customers,
        ]);
    }

    public function show($id)
    {
        $customer = Customer::with([
            'individualDetail',
            'corporateDetail.relatedPersons',
            'products',
            'countryOperations',
            'documents',
        ])->find($id);

        if (!$customer) {
            return response()->json(['status' => false, 'message' => 'Customer not found'], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer retrieved successfully',
            'data' => $customer,
        ]);
    }

    /**
     * Store new customer onboarding
     */
    public function store(Request $request)
    {
        Log::info('Customer Onboarding Payload: ', $request->all());
        // Accept either raw JSON body or FormData with `data` JSON field
        $raw = $request->input('data');
        $payload = $raw ? json_decode($raw, true) ?? [] : $request->all();

        // Basic validation for required top-level fields
        $topValidator = Validator::make($payload, [
            'customer_type' => 'required|in:individual,corporate',
            'onboarding_type' => 'required|in:full,quick_single,quick_batch',
        ]);

        if ($topValidator->fails()) {
            return response()->json(['status' => false, 'errors' => $topValidator->errors()], 422);
        }

        $user = $request->user();

        try {
            $customer = DB::transaction(function () use ($payload, $user, $request) {
                // Create customer
                $customer = Customer::create([
                    'user_id' => $user->id,
                    'customer_type' => $payload['customer_type'],
                    'onboarding_type' => $payload['onboarding_type'],
                    'screening_fuzziness' => $payload['screening_fuzziness'] ?? 'OFF',
                    'risk_level' => $payload['risk_level'] ?? null,
                    'remarks' => $payload['remarks'] ?? null,
                ]);

                // INDIVIDUAL
                if ($payload['customer_type'] === 'individual') {
                    $ind = $payload['individual_details'] ?? $payload['individual'] ?? $payload;

                    $indValidator = Validator::make($ind, [
                        'first_name' => 'required|string',
                        'last_name' => 'required|string',
                        'dob' => 'required|date',
                        'residential_status' => 'required|in:resident,non-resident',
                        'address' => 'required|string',
                        'nationality' => 'required|string',
                        'country_code' => 'required|string',
                        'contact_no' => 'required|string',
                        'email' => 'required|email',
                        'id_type' => 'required|string',
                        'id_no' => 'required|string',
                    ]);

                    $indValidator->validate();

                    $indData = [
                        'customer_id' => $customer->id,
                        'first_name' => $ind['first_name'],
                        'last_name' => $ind['last_name'],
                        'dob' => $ind['dob'],
                        'residential_status' => $ind['residential_status'],
                        'address' => $ind['address'],
                        'city' => $ind['city'] ?? null,
                        'country' => $ind['country'] ?? null,
                        'nationality' => $ind['nationality'],
                        'country_code' => $ind['country_code'],
                        'contact_no' => $ind['contact_no'],
                        'email' => $ind['email'],
                        'place_of_birth' => $ind['place_of_birth'] ?? null,
                        'country_of_residence' => $ind['country_of_residence'] ?? null,
                        'dual_nationality' => !empty($ind['dual_nationality']),
                        'adverse_news' => !empty($ind['adverse_news']),
                        'gender' => $ind['gender'] ?? null,
                        'is_pep' => !empty($ind['is_pep']),
                        'occupation' => $ind['occupation'] ?? null,
                        'source_of_income' => $ind['source_of_income'] ?? null,
                        'purpose_of_onboarding' => $ind['purpose_of_onboarding'] ?? null,
                        'payment_mode' => $ind['payment_mode'] ?? null,
                        'id_type' => $ind['id_type'],
                        'id_no' => $ind['id_no'],
                        'issuing_authority' => $ind['issuing_authority'] ?? null,
                        'issuing_country' => $ind['issuing_country'] ?? null,
                        'id_issue_date' => $ind['id_issue_date'] ?? null,
                        'id_expiry_date' => $ind['id_expiry_date'] ?? null,
                    ];

                    IndividualCustomerDetail::create($indData);
                }

                // CORPORATE
                if ($payload['customer_type'] === 'corporate') {
                    $corp = $payload['corporate_details'] ?? $payload;

                    $corpValidator = Validator::make($corp, [
                        'company_name' => 'required|string',
                        'company_address' => 'required|string',
                        'country_incorporated' => 'required|string',
                        'email' => 'required|email',
                    ]);

                    $corpValidator->validate();

                    $corpData = [
                        'customer_id' => $customer->id,
                        'company_name' => $corp['company_name'],
                        'company_address' => $corp['company_address'],
                        'city' => $corp['city'] ?? null,
                        'country_incorporated' => $corp['country_incorporated'],
                        'po_box' => $corp['po_box'] ?? null,
                        'customer_type' => $corp['customer_type'] ?? null,
                        'office_country_code' => $corp['office_country_code'] ?? null,
                        'office_no' => $corp['office_no'] ?? null,
                        'mobile_country_code' => $corp['mobile_country_code'] ?? null,
                        'mobile_no' => $corp['mobile_no'] ?? null,
                        'email' => $corp['email'],
                        'trade_license_no' => $corp['trade_license_no'] ?? null,
                        'trade_license_issued_at' => $corp['trade_license_issued_at'] ?? null,
                        'trade_license_issued_by' => $corp['trade_license_issued_by'] ?? null,
                        'license_issue_date' => $corp['license_issue_date'] ?? null,
                        'license_expiry_date' => $corp['license_expiry_date'] ?? null,
                        'vat_registration_no' => $corp['vat_registration_no'] ?? null,
                        'tenancy_contract_expiry_date' => $corp['tenancy_contract_expiry_date'] ?? null,
                        'entity_type' => $corp['entity_type'] ?? null,
                        'business_activity' => $corp['business_activity'] ?? null,
                        'is_entity_dealting_with_import_export' => !empty($corp['is_entity_dealting_with_import_export']),
                        'has_sister_concern' => !empty($corp['has_sister_concern']),
                        'account_holding_bank_name' => $corp['account_holding_bank_name'] ?? null,
                        'product_source' => $corp['product_source'] ?? null,
                        'payment_mode' => $corp['payment_mode'] ?? null,
                        'delivery_channel' => $corp['delivery_channel'] ?? null,
                        'expected_no_of_transactions' => $corp['expected_no_of_transactions'] ?? null,
                        'expected_volume' => $corp['expected_volume'] ?? null,
                        'dual_use_goods' => !empty($corp['dual_use_goods']),
                        'kyc_documents_collected_with_form' => isset($corp['kyc_documents_collected_with_form']) ? (bool)$corp['kyc_documents_collected_with_form'] : true,
                        'is_entity_registered_in_GOAML' => isset($corp['is_entity_registered_in_GOAML']) ? (bool)$corp['is_entity_registered_in_GOAML'] : true,
                        'is_entity_having_adverse_news' => !empty($corp['is_entity_having_adverse_news']),
                    ];

                    $createdCorp = CorporateCustomerDetail::create($corpData);

                    // related persons (optional)
                    $relatedPersons = $payload['corporate_related_persons'] ?? $corp['corporate_related_persons'] ?? $corp['related_persons'] ?? [];

                    if (!empty($relatedPersons) && is_array($relatedPersons)) {
                        foreach ($relatedPersons as $rp) {
                            $rpData = [
                                'type' => $rp['type'] ?? 'individual',
                                'name' => $rp['name'] ?? null,
                                'is_pep' => !empty($rp['is_pep']),
                                'nationality' => $rp['nationality'] ?? null,
                                'id_type' => $rp['id_type'] ?? null,
                                'id_no' => $rp['id_no'] ?? null,
                                // Map payload keys (id_issue) to DB columns (id_issue_date)
                                'id_issue_date' => $rp['id_issue'] ?? $rp['id_issue_date'] ?? null,
                                'id_expiry_date' => $rp['id_expiry'] ?? $rp['id_expiry_date'] ?? null,
                                'dob' => $rp['dob'] ?? null,
                                'role' => $rp['role'] ?? null,
                                'ownership_percentage' => $rp['ownership_percentage'] ?? null,
                            ];

                            // Create using relationship to automatically set foreign key
                            $createdCorp->relatedPersons()->create($rpData);
                        }
                    }
                }

                // PRODUCTS - accept array of scalar ids (['1','2']) or objects {product_id, quantity, price, notes}
                if (!empty($payload['products']) && is_array($payload['products'])) {
                    $attach = [];
                    foreach ($payload['products'] as $p) {
                        // If product is a scalar id (string or int)
                        if (is_scalar($p)) {
                            $productId = (int) $p;
                            if ($productId <= 0) continue;
                            $attach[$productId] = [
                                'quantity' => null,
                                'price' => null,
                                'notes' => null,
                            ];
                            continue;
                        }

                        // If product is an array/object with details
                        if (is_array($p)) {
                            $productId = $p['product_id'] ?? $p['id'] ?? null;
                            if (!$productId) continue;
                            $attach[(int)$productId] = [
                                'quantity' => $p['quantity'] ?? null,
                                'price' => $p['price'] ?? null,
                                'notes' => $p['notes'] ?? null,
                            ];
                        }
                    }

                    if (!empty($attach)) {
                        // Use syncWithoutDetaching to avoid duplicate primary key errors
                        $customer->products()->syncWithoutDetaching($attach);
                    }
                }

                // COUNTRY OPERATIONS
                if (!empty($payload['country_operations']) && is_array($payload['country_operations'])) {
                    foreach ($payload['country_operations'] as $c) {
                        if (is_string($c) && !empty($c)) {
                            CountryOperation::create([
                                'customer_id' => $customer->id,
                                'country' => $c,
                            ]);
                        } elseif (is_array($c) && !empty($c['country'])) {
                            CountryOperation::create([
                                'customer_id' => $customer->id,
                                'country' => $c['country'],
                                'notes' => $c['notes'] ?? null,
                            ]);
                        }
                    }
                }

                // DOCUMENTS - handle uploaded files (FormData) and optional payload document entries
                // Uploaded files should be in `documents[]`
                $uploaded = $request->file('documents');
                if (!empty($uploaded)) {
                    foreach ($uploaded as $file) {
                        if (!$file->isValid()) continue;

                        $filename = Str::random(12) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
                        $path = $file->storeAs('customer_documents', $filename, 'public');

                        CustomerDocument::create([
                            'customer_id' => $customer->id,
                            'document_type' => null,
                            'file_path' => $path,
                            'file_name' => $file->getClientOriginalName(),
                        ]);
                    }
                }

                // Also support documents provided in payload as objects with file_path (already uploaded elsewhere)
                if (!empty($payload['documents']) && is_array($payload['documents'])) {
                    foreach ($payload['documents'] as $d) {
                        if (empty($d['file_path']) || empty($d['file_name'])) continue;

                        CustomerDocument::create([
                            'customer_id' => $customer->id,
                            'document_type' => $d['document_type'] ?? null,
                            'file_path' => $d['file_path'],
                            'file_name' => $d['file_name'],
                        ]);
                    }
                }

                return $customer;
            });
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()], 500);
        }

        $customer->load(['individualDetail','corporateDetail','products','countryOperations','documents']);

        return response()->json([
            'status' => true,
            'message' => 'Customer onboarded successfully',
            'data' => $customer,
        ], 201);
    }
}
