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
use App\Services\RiskCalculationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerOnboardingController extends Controller
{
    protected $riskCalculationService;

    public function __construct(RiskCalculationService $riskCalculationService)
    {
        $this->riskCalculationService = $riskCalculationService;
    }
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

        // Prepare risk calculation breakdown
        $riskBreakdown = null;
        $riskLevel = null;

        if ($customer->customer_type === 'individual' && $customer->individualDetail) {
            $ind = $customer->individualDetail;
            $productIds = $customer->products->pluck('id')->toArray();
            $country = $ind->nationality ?? null;
            $occupation = $ind->occupation ?? null;
            $sourceOfIncome = $ind->source_of_income ?? null;
            $paymentMode = $ind->payment_mode ?? null;
            $modeOfApproach = $ind->mode_of_approach ?? null;
            $isPep = $ind->is_pep ?? null;

            // Calculate each component
            $geoScore = $this->riskCalculationService->calculateCountryRisk($country);
            $productScore = $this->riskCalculationService->calculateProductRisk($productIds, $paymentMode, \App\Services\RiskCalculationService::PAYMENT_METHODS);
            $channelScore = $this->riskCalculationService->getScoreFromList($modeOfApproach, \App\Services\RiskCalculationService::MODE_OF_APPROACH);
            $occScore = $this->riskCalculationService->getScoreFromList($occupation, \App\Services\RiskCalculationService::OCCUPATION);
            $soiScore = $this->riskCalculationService->getScoreFromList($sourceOfIncome, \App\Services\RiskCalculationService::SOURCE_OF_INCOME);
            $pepScore = $isPep ? 4 : 1;
            $customerAvg = ($occScore + $soiScore + $pepScore) / 3;

            $riskLevel = ($customerAvg * 0.40) + ($geoScore * 0.25) + ($productScore * 0.20) + ($channelScore * 0.15);

            $riskBreakdown = [
                'customer_avg' => $customerAvg,
                'customer_weighted' => $customerAvg * 0.40,
                'geo_score' => $geoScore,
                'geo_weighted' => $geoScore * 0.25,
                'product_score' => $productScore,
                'product_weighted' => $productScore * 0.20,
                'channel_score' => $channelScore,
                'channel_weighted' => $channelScore * 0.15,
                'final_risk_level' => $riskLevel,
            ];
        } elseif ($customer->customer_type === 'corporate' && $customer->corporateDetail) {
            $corp = $customer->corporateDetail;
            $relatedPersons = $corp->relatedPersons ? $corp->relatedPersons->toArray() : [];
            $businessActivity = $corp->business_activity ?? null;
            $country = $corp->country_incorporated ?? null;
            $productIds = $customer->products->pluck('id')->toArray();
            $paymentMode = $corp->payment_mode ?? null;
            $deliveryChannel = $corp->delivery_channel ?? null;

            $ownershipScore = $this->riskCalculationService->ownershipRisk($relatedPersons);
            $businessActivityScore = $this->riskCalculationService->getScoreFromList($businessActivity, \App\Services\RiskCalculationService::BUSINESS_ACTIVITIES);
            $countryIncorporateScore = $this->riskCalculationService->getScoreFromList($country, \App\Services\RiskCalculationService::HIGH_RISK_COUNTRIES);
            $productScore = $this->riskCalculationService->calculateProductRisk($productIds, $paymentMode, \App\Services\RiskCalculationService::PAYMENT_METHODS);
            $channelScore = $this->riskCalculationService->getScoreFromList($deliveryChannel, \App\Services\RiskCalculationService::DELIVERY_CHANNEL);

            $riskLevel = ($ownershipScore * 0.30) + ($businessActivityScore * 0.20) + ($countryIncorporateScore * 0.20) + ($productScore * 0.15) + ($channelScore * 0.15);

            $riskBreakdown = [
                'ownership_score' => $ownershipScore,
                'ownership_weighted' => $ownershipScore * 0.30,
                'business_activity_score' => $businessActivityScore,
                'business_activity_weighted' => $businessActivityScore * 0.20,
                'country_incorporate_score' => $countryIncorporateScore,
                'country_incorporate_weighted' => $countryIncorporateScore * 0.20,
                'product_score' => $productScore,
                'product_weighted' => $productScore * 0.15,
                'channel_score' => $channelScore,
                'channel_weighted' => $channelScore * 0.15,
                'final_risk_level' => $riskLevel,
            ];
        }
        $customer->riskBreakdown = $riskBreakdown;

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
        $company = $user->company;

        try {
            $customer = DB::transaction(function () use ($payload, $user, $request) {
                // Determine country for risk calculation
                $country = null;
                if ($payload['customer_type'] === 'individual') {
                    $ind = $payload['individual_details'] ?? $payload['individual'] ?? $payload;
                    $country = $ind['nationality'] ?? null;
                } elseif ($payload['customer_type'] === 'corporate') {
                    $corp = $payload['corporate_details'] ?? $payload;
                    $country = $corp['country_incorporated'] ?? null;
                }

                // Extract product IDs for risk calculation
                $productIds = [];
                if (!empty($payload['products']) && is_array($payload['products'])) {
                    foreach ($payload['products'] as $p) {
                        if (is_scalar($p)) {
                            $productIds[] = (int) $p;
                        } elseif (is_array($p)) {
                            $productId = $p['product_id'] ?? $p['id'] ?? null;
                            if ($productId) {
                                $productIds[] = (int) $productId;
                            }
                        }
                    }
                }
                $occupation = null;
                $sourceOfIncome = null;
                $paymentMode = null;
                $modeAfApproach = null;
                $isPep = null;
                if($payload['customer_type'] === 'individual'){
                    $occupation =  $ind['occupation'] ;
                    $sourceOfIncome = $ind['source_of_income'];
                    $paymentMode = $ind['payment_mode'];
                    $modeAfApproach = $ind['mode_of_approach'];
                    $isPep = $ind['is_pep'];
                }

                // Calculate risk level
                $calculatedRiskLevel = 1;
                $payload['customer_type'] === 'individual' ? $calculatedRiskLevel = $this->riskCalculationService->calculateIndividualRiskLevel($productIds, $country, $occupation, $sourceOfIncome, $paymentMode, $modeAfApproach, $isPep) : $calculatedRiskLevel = $this->riskCalculationService->calculateCorporateRiskLevel($payload['corporate_related_persons'], $payload['corporate_details']['business_activity'], $payload['corporate_details']['country_incorporated'], $productIds, $paymentMode, $payload['corporate_details']['delivery_channel']);
                
                Log::info('Risk calculation', [
                    'country' => $country,
                    'products' => $productIds,
                    'calculated_risk' => $calculatedRiskLevel
                ]);

                // Create customer
                $customer = Customer::create([
                    'user_id' => $user->id,
                    'company_information_id' => $user->company?->id,
                    'customer_type' => $payload['customer_type'],
                    'onboarding_type' => $payload['onboarding_type'],
                    'screening_fuzziness' => $payload['screening_fuzziness'] ?? 'OFF',
                    'risk_level' => $calculatedRiskLevel, // Use calculated risk
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
                        'mode_of_approach' => $ind['mode_of_approach'] ?? null,
                        'id_type' => $ind['id_type'],
                        'id_no' => $ind['id_no'],
                        'issuing_authority' => $ind['issuing_authority'] ?? null,
                        'issuing_country' => $ind['issuing_country'] ?? null,
                        'id_issue_date' => $ind['id_issue_date'] ?? null,
                        'id_expiry_date' => $ind['id_expiry_date'] ?? null,
                        'expected_volume' => $ind['expected_volume'] ?? null,
                        'expected_no_of_transactions' => $ind['expected_no_of_transactions'] ?? null,
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
                                'id_issue_date' => $rp['id_issue'] ?? $rp['id_issue_date'] ?? null,
                                'id_expiry_date' => $rp['id_expiry'] ?? $rp['id_expiry_date'] ?? null,
                                'dob' => $rp['dob'] ?? null,
                                'role' => $rp['role'] ?? null,
                                'ownership_percentage' => $rp['ownership_percentage'] ?? null,
                            ];

                            $createdCorp->relatedPersons()->create($rpData);
                        }
                    }
                }

                // PRODUCTS
                if (!empty($payload['products']) && is_array($payload['products'])) {
                    $attach = [];
                    foreach ($payload['products'] as $p) {
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

                // DOCUMENTS
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

    public function update(Request $request, $id)
    {
        Log::info('Customer Update Payload: ', $request->all());
        
        $raw = $request->input('data');
        $payload = $raw ? json_decode($raw, true) ?? [] : $request->all();

        $topValidator = Validator::make($payload, [
            'customer_type' => 'sometimes|in:individual,corporate',
            'onboarding_type' => 'sometimes|in:full,quick_single,quick_batch',
        ]);

        if ($topValidator->fails()) {
            return response()->json(['status' => false, 'errors' => $topValidator->errors()], 422);
        }

        $user = $request->user();
        $filterUserId = $this->getFilterUserId($user);

        if (!$filterUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        // try {
            $customer = DB::transaction(function () use ($id, $payload, $filterUserId, $user, $request) {
                $customer = Customer::where('id', $id)
                    ->where('user_id', $filterUserId)
                    ->first();

                if (!$customer) {
                    throw new \Exception('Customer not found or unauthorized access');
                }

                // Recalculate risk if products or country changed
                $shouldRecalculateRisk = isset($payload['products']) || 
                                        isset($payload['individual_details']) || 
                                        isset($payload['corporate_details']);

                if ($shouldRecalculateRisk) {
                    // Determine country
                    $country = null;
                    if ($customer->customer_type === 'individual') {
                        $ind = $payload['individual_details'] ?? [];
                        $country = $ind['nationality'] ?? $customer->individualDetail?->nationality;
                    } elseif ($customer->customer_type === 'corporate') {
                        $corp = $payload['corporate_details'] ?? [];
                        $country = $corp['country_incorporated'] ?? $customer->corporateDetail?->country_incorporated;
                    }

                    // Extract product IDs
                    $productIds = [];
                    if (isset($payload['products']) && is_array($payload['products'])) {
                        foreach ($payload['products'] as $p) {
                            if (is_scalar($p)) {
                                $productIds[] = (int) $p;
                            } elseif (is_array($p)) {
                                $productId = $p['product_id'] ?? $p['id'] ?? null;
                                if ($productId) {
                                    $productIds[] = (int) $productId;
                                }
                            }
                        }
                    } else {
                        // Use existing products
                        $productIds = $customer->products()->pluck('products.id')->toArray();
                    }
                    $occupation = null;
                    $sourceOfIncome = null;
                    $paymentMode = null;
                    $modeAfApproach = null;
                    $isPep = null;
                    if($customer->customer_type === 'individual'){
                        $occupation =  $ind['occupation'] ;
                        $sourceOfIncome = $ind['source_of_income'];
                        $paymentMode = $ind['payment_mode'];
                        $modeAfApproach = $ind['mode_of_approach'];
                        $isPep = $ind['is_pep'];
                    }
                    // Log::info("result: ", $ind['occupation']);
                    $calculatedRiskLevel = $this->riskCalculationService->calculateIndividualRiskLevel($productIds, $country, $occupation , $sourceOfIncome, $paymentMode, $modeAfApproach, $isPep );
                    
                    Log::info('Risk recalculation on update', [
                        'customer_id' => $id,
                        'country' => $country,
                        'products' => $productIds,
                        'calculated_risk' => $calculatedRiskLevel
                    ]);
                } else {
                    $calculatedRiskLevel = $customer->risk_level;
                }

                // Update customer base fields
                $customer->update([
                    'customer_type' => $payload['customer_type'] ?? $customer->customer_type,
                    'onboarding_type' => $payload['onboarding_type'] ?? $customer->onboarding_type,
                    'screening_fuzziness' => $payload['screening_fuzziness'] ?? $customer->screening_fuzziness,
                    'risk_level' => $calculatedRiskLevel, // Use calculated risk
                    'remarks' => $payload['remarks'] ?? $customer->remarks,
                ]);

                // ...rest of update logic (INDIVIDUAL, CORPORATE, PRODUCTS, etc.) remains the same...
                // INDIVIDUAL
                if ($customer->customer_type === 'individual' && isset($payload['individual_details'])) {
                    $ind = $payload['individual_details'];

                    $indValidator = Validator::make($ind, [
                        'first_name' => 'sometimes|required|string',
                        'last_name' => 'sometimes|required|string',
                        'dob' => 'sometimes|required|date',
                        'residential_status' => 'sometimes|required|in:resident,non-resident',
                        'address' => 'sometimes|required|string',
                        'nationality' => 'sometimes|required|string',
                        'country_code' => 'sometimes|required|string',
                        'contact_no' => 'sometimes|required|string',
                        'email' => 'sometimes|required|email',
                        'id_type' => 'sometimes|required|string',
                        'id_no' => 'sometimes|required|string',
                    ]);

                    $indValidator->validate();

                    $indData = [
                        'first_name' => $ind['first_name'] ?? null,
                        'last_name' => $ind['last_name'] ?? null,
                        'dob' => $ind['dob'] ?? null,
                        'residential_status' => $ind['residential_status'] ?? null,
                        'address' => $ind['address'] ?? null,
                        'city' => $ind['city'] ?? null,
                        'country' => $ind['country'] ?? null,
                        'nationality' => $ind['nationality'] ?? null,
                        'country_code' => $ind['country_code'] ?? null,
                        'contact_no' => $ind['contact_no'] ?? null,
                        'email' => $ind['email'] ?? null,
                        'place_of_birth' => $ind['place_of_birth'] ?? null,
                        'country_of_residence' => $ind['country_of_residence'] ?? null,
                        'dual_nationality' => isset($ind['dual_nationality']) ? !empty($ind['dual_nationality']) : null,
                        'adverse_news' => isset($ind['adverse_news']) ? !empty($ind['adverse_news']) : null,
                        'gender' => $ind['gender'] ?? null,
                        'is_pep' => isset($ind['is_pep']) ? !empty($ind['is_pep']) : null,
                        'occupation' => $ind['occupation'] ?? null,
                        'source_of_income' => $ind['source_of_income'] ?? null,
                        'purpose_of_onboarding' => $ind['purpose_of_onboarding'] ?? null,
                        'payment_mode' => $ind['payment_mode'] ?? null,
                        'mode_of_approach' => $ind['mode_of_approach'] ?? null,
                        'id_type' => $ind['id_type'] ?? null,
                        'id_no' => $ind['id_no'] ?? null,
                        'issuing_authority' => $ind['issuing_authority'] ?? null,
                        'issuing_country' => $ind['issuing_country'] ?? null,
                        'id_issue_date' => $ind['id_issue_date'] ?? null,
                        'id_expiry_date' => $ind['id_expiry_date'] ?? null,
                        'expected_volume' => $ind['expected_volume'] ?? null,
                        'expected_no_of_transactions' => $ind['expected_no_of_transactions'] ?? null,
                    ];

                    $indData = array_filter($indData, fn($v) => $v !== null);

                    $customer->individualDetail()->updateOrCreate(
                        ['customer_id' => $customer->id],
                        $indData
                    );
                }

                // CORPORATE
                if ($customer->customer_type === 'corporate' && isset($payload['corporate_details'])) {
                    $corp = $payload['corporate_details'];

                    $corpValidator = Validator::make($corp, [
                        'company_name' => 'sometimes|required|string',
                        'company_address' => 'sometimes|required|string',
                        'country_incorporated' => 'sometimes|required|string',
                        'email' => 'sometimes|required|email',
                    ]);

                    $corpValidator->validate();

                    $corpData = [
                        'company_name' => $corp['company_name'] ?? null,
                        'company_address' => $corp['company_address'] ?? null,
                        'city' => $corp['city'] ?? null,
                        'country_incorporated' => $corp['country_incorporated'] ?? null,
                        'po_box' => $corp['po_box'] ?? null,
                        'customer_type' => $corp['customer_type'] ?? null,
                        'office_country_code' => $corp['office_country_code'] ?? null,
                        'office_no' => $corp['office_no'] ?? null,
                        'mobile_country_code' => $corp['mobile_country_code'] ?? null,
                        'mobile_no' => $corp['mobile_no'] ?? null,
                        'email' => $corp['email'] ?? null,
                        'trade_license_no' => $corp['trade_license_no'] ?? null,
                        'trade_license_issued_at' => $corp['trade_license_issued_at'] ?? null,
                        'trade_license_issued_by' => $corp['trade_license_issued_by'] ?? null,
                        'license_issue_date' => $corp['license_issue_date'] ?? null,
                        'license_expiry_date' => $corp['license_expiry_date'] ?? null,
                        'vat_registration_no' => $corp['vat_registration_no'] ?? null,
                        'tenancy_contract_expiry_date' => $corp['tenancy_contract_expiry_date'] ?? null,
                        'entity_type' => $corp['entity_type'] ?? null,
                        'business_activity' => $corp['business_activity'] ?? null,
                        'is_entity_dealting_with_import_export' => isset($corp['is_entity_dealting_with_import_export']) ? !empty($corp['is_entity_dealting_with_import_export']) : null,
                        'has_sister_concern' => isset($corp['has_sister_concern']) ? !empty($corp['has_sister_concern']) : null,
                        'account_holding_bank_name' => $corp['account_holding_bank_name'] ?? null,
                        'product_source' => $corp['product_source'] ?? null,
                        'payment_mode' => $corp['payment_mode'] ?? null,
                        'delivery_channel' => $corp['delivery_channel'] ?? null,
                        'expected_no_of_transactions' => $corp['expected_no_of_transactions'] ?? null,
                        'expected_volume' => $corp['expected_volume'] ?? null,
                        'dual_use_goods' => isset($corp['dual_use_goods']) ? !empty($corp['dual_use_goods']) : null,
                        'kyc_documents_collected_with_form' => isset($corp['kyc_documents_collected_with_form']) ? (bool)$corp['kyc_documents_collected_with_form'] : null,
                        'is_entity_registered_in_GOAML' => isset($corp['is_entity_registered_in_GOAML']) ? (bool)$corp['is_entity_registered_in_GOAML'] : null,
                        'is_entity_having_adverse_news' => isset($corp['is_entity_having_adverse_news']) ? !empty($corp['is_entity_having_adverse_news']) : null,
                    ];

                    $corpData = array_filter($corpData, fn($v) => $v !== null);

                    $updatedCorp = $customer->corporateDetail()->updateOrCreate(
                        ['customer_id' => $customer->id],
                        $corpData
                    );

                    if (isset($payload['corporate_related_persons'])) {
                        $relatedPersons = $payload['corporate_related_persons'];
                        $updatedCorp->relatedPersons()->delete();

                        if (!empty($relatedPersons) && is_array($relatedPersons)) {
                            foreach ($relatedPersons as $rp) {
                                $rpData = [
                                    'type' => $rp['type'] ?? 'individual',
                                    'name' => $rp['name'] ?? null,
                                    'is_pep' => !empty($rp['is_pep']),
                                    'nationality' => $rp['nationality'] ?? null,
                                    'id_type' => $rp['id_type'] ?? null,
                                    'id_no' => $rp['id_no'] ?? null,
                                    'id_issue_date' => $rp['id_issue'] ?? $rp['id_issue_date'] ?? null,
                                    'id_expiry_date' => $rp['id_expiry'] ?? $rp['id_expiry_date'] ?? null,
                                    'dob' => $rp['dob'] ?? null,
                                    'role' => $rp['role'] ?? null,
                                    'ownership_percentage' => $rp['ownership_percentage'] ?? null,
                                ];

                                $updatedCorp->relatedPersons()->create($rpData);
                            }
                        }
                    }
                }

                if (isset($payload['products']) && is_array($payload['products'])) {
                    $attach = [];
                    foreach ($payload['products'] as $p) {
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
                        $customer->products()->sync($attach);
                    }
                }

                if (isset($payload['country_operations']) && is_array($payload['country_operations'])) {
                    $customer->countryOperations()->delete();

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

                if (isset($payload['documents']) && is_array($payload['documents'])) {
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
        // } catch (\Exception $e) {
        //     Log::error('Customer update failed: ' . $e->getMessage());
        //     return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        // }

        $customer->load(['individualDetail','corporateDetail.relatedPersons','products','countryOperations','documents']);

        return response()->json([
            'status' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer,
        ], 200);
    }

    /**
     * Delete customer (soft delete recommended, but hard delete shown here)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $filterUserId = $this->getFilterUserId($user);

        if (!$filterUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        try {
            DB::transaction(function () use ($id, $filterUserId) {
                $customer = Customer::where('id', $id)
                    ->where('user_id', $filterUserId)
                    ->first();

                if (!$customer) {
                    throw new \Exception('Customer not found or unauthorized access');
                }

                // Delete related data (cascade should handle this if foreign keys are set up properly)
                $customer->individualDetail()->delete();
                $customer->corporateDetail()->delete(); // cascades to relatedPersons
                $customer->products()->detach();
                $customer->countryOperations()->delete();
                
                // Delete documents from storage
                foreach ($customer->documents as $doc) {
                    if (Storage::disk('public')->exists($doc->file_path)) {
                        Storage::disk('public')->delete($doc->file_path);
                    }
                    $doc->delete();
                }

                $customer->delete();
            });
        } catch (\Exception $e) {
            Log::error('Customer deletion failed: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer deleted successfully',
        ], 200);
    }
}
