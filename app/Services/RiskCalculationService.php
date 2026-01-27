<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class RiskCalculationService
{
    /**
     * High-risk countries (risk level 5)
     */
    public const HIGH_RISK_COUNTRIES = [
        ['label' => 'North Korea (DPRK)', 'risk_score' => 5],
        ['label' => 'Iran', 'risk_score' => 5],
        ['label' => 'Myanmar', 'risk_score' => 5],
        ['label' => 'Algeria', 'risk_score' => 4],
        ['label' => 'Angola', 'risk_score' => 4],
        ['label' => 'Bolivia', 'risk_score' => 4],
        ['label' => 'Bulgaria', 'risk_score' => 4],
        ['label' => 'Cameroon', 'risk_score' => 4],
        ['label' => "Cote D''Ivoire (Ivory Coast)", 'risk_score' => 4],
        ['label' => 'Democratic Republic of Congo', 'risk_score' => 4],
        ['label' => 'Haiti', 'risk_score' => 4],
        ['label' => 'Kenya', 'risk_score' => 4],
        ['label' => 'Laos', 'risk_score' => 4],
        ['label' => 'Lebanon', 'risk_score' => 4],
        ['label' => 'Monaco', 'risk_score' => 4],
        ['label' => 'Mali', 'risk_score' => 4],
        ['label' => 'Namibia', 'risk_score' => 4],
        ['label' => 'Nepal', 'risk_score' => 4],
        ['label' => 'Philippines', 'risk_score' => 4],
        ['label' => 'South Sudan', 'risk_score' => 4],
        ['label' => 'Syria', 'risk_score' => 4],
        ['label' => 'Venezuela', 'risk_score' => 4],
        ['label' => 'Vietnam', 'risk_score' => 4],
        ['label' => 'Virgin Islands (British)', 'risk_score' => 4],
        ['label' => 'Yemen', 'risk_score' => 4],
        ['label' => 'Afghanistan', 'risk_score' => 3],
        ['label' => 'Burkina Faso', 'risk_score' => 3],
        ['label' => 'Croatia (Hrvatska)', 'risk_score' => 3],
        ['label' => 'Mozambique', 'risk_score' => 3],
        ['label' => 'Nigeria', 'risk_score' => 3],
        ['label' => 'Senegal', 'risk_score' => 3],
        ['label' => 'South Africa', 'risk_score' => 3],
        ['label' => 'Tanzania', 'risk_score' => 3],
    ];
    public const OCCUPATION = [
        ['value' => 'Accounting', 'label' => 'Accounting', 'risk_score' => 3],
        ['value' => 'Advocacy Organizations', 'label' => 'Self Employed', 'risk_score' => 3],
        ['value' => 'Air Couriers and Cargo Services', 'label' => 'Air Couriers and Cargo Services', 'risk_score' => 4],
        ['value' => 'Advertising, Marketing and PR', 'label' => 'Advertising, Marketing and PR', 'risk_score' => 1],
        ['value' => 'Banking/Financial Institutions', 'label' => 'Banking/Financial Institutions', 'risk_score' => 2],
        ['value' => 'Business Services Other', 'label' => 'Business Services Other', 'risk_score' => 1],
        ['value' => 'Charitable Organizations and Foundations', 'label' => 'Charitable Organizations and Foundations', 'risk_score' => 5],
        ['value' => 'Counsulting/Freelancer', 'label' => 'Counsulting/Freelancer', 'risk_score' => 3],
        ['value' => 'Data Analystics, Management and Internet', 'label' => 'Data Analystics, Management and Internet', 'risk_score' => 1],
        ['value' => 'Defense', 'label' => 'Defense', 'risk_score' => 5],
        ['value' => 'Education', 'label' => 'Education', 'risk_score' => 1],
        ['value' => 'Facilities Management and Maintenance', 'label' => 'Facilities Management and Maintenance', 'risk_score' => 1],
        ['value' => 'Government Service', 'label' => 'Government Service', 'risk_score' => 2],
        ['value' => 'HR and Recruiting Services', 'label' => 'HR and Recruiting Services', 'risk_score' => 1],
        ['value' => 'HealthCare', 'label' => 'HealthCare', 'risk_score' => 1],
        ['value' => 'IT and Network Services and Support', 'label' => 'IT and Network Services and Support', 'risk_score' => 1],
        ['value' => 'Jewellery Trading', 'label' => 'Jewellery Trading', 'risk_score' => 4], // Cash Intensive
        ['value' => 'Outside UAE', 'label' => 'Outside UAE', 'risk_score' => 4],
        ['value' => 'Sale and Services', 'label' => 'Sale and Services', 'risk_score' => 1],
        ['value' => 'Others', 'label' => 'Others', 'risk_score' => 3],
        ['value' => 'Owner/Partner/Director', 'label' => 'Owner/Partner/Director', 'risk_score' => 5], // Opaque/Complex
    ];

    public const SOURCE_OF_INCOME = [
        [ 'value'=> "Salary", 'label' => "Salary", 'risk_score'=> 1 ],
        [ 'value'=> "Perosonal Savings", 'label' => "Perosonal Savings", 'risk_score'=> 1 ],
        [ 'value'=> "Bank - Cash Withdrawal Slip", 'label' => "Bank - Cash Withdrawal Slip", 'risk_score'=> 3 ],
        [ 'value'=> "Funds from Dividend Payouts", 'label' => "Funds from Dividend Payouts", 'risk_score'=> 1 ],
        [ 'value'=> "End of Services Funds", 'label' => "End of Services Funds", 'risk_score'=> 1 ],
        [ 'value'=> "Business Proceeds", 'label' => "Business Proceeds", 'risk_score'=> 3 ],
        [ 'value'=> "Other sources", 'label' => "Other sources", 'risk_score'=> 3 ],
        [ 'value'=> "Gift", 'label' => "Gift", 'risk_score'=> 4 ],
        [ 'value'=> "Loan from Friends and Family", 'label' => "Loan from Friends and Family", 'risk_score'=> 5 ],
        [ 'value'=> "Loans from Bank", 'label' => "Loans from Bank", 'risk_score'=> 1 ],
        [ 'value'=> "Loan from Financial Institutions", 'label' => "Loan from Financial Institutions", 'risk_score'=> 1 ],
        [ 'value'=> "Lottery/Raffles", 'label' => "Lottery/Raffles", 'risk_score'=> 5 ],
    ];

    public const PAYMENT_METHODS = [
        [ 'value'=> "Cash", 'label' => "Cash", 'risk_score' => 1 ],
        [ 'value'=> "Debit/Credit Card", 'label' => "Debit/Credit Card", 'risk_score' => 1 ],
        [ 'value'=> "Bank Transfer - Inside UAE", 'label' => "Bank Transfer - Inside UAE", 'risk_score' => 1 ],
        [ 'value'=> "Bank Transfer - Outside UAE", 'label' => "Bank Transfer _ Outside UAE", 'risk_score' => 4 ], // Cross-border
        [ 'value'=> "Parial Cash/Card/Online trs", 'label' => "Parial Cash/Card/Online trs", 'risk_score' => 3 ],
        [ 'value'=> "Crypto/Prepaid Cards", 'label' => "Crypto/Prepaid Cards", 'risk_score' => 5 ], // Very High
        [ 'value'=> "Old Gold Exchange", 'label' => "Old Gold Exchange", 'risk_score' => 4 ],
        [ 'value'=> "Payment from HRC", 'label' => "Payment from HRC", 'risk_score' => 3 ],
        [ 'value'=> "Others", 'label' => "Others", 'risk_score' => 3 ],
    ];

    public const MODE_OF_APPROACH = [
        [ 'value'=> "Walk-In Customer", 'label' => "Walk-In Customer", 'risk_score' => 1 ],
        [ 'value'=> "Non Face to Face", 'label' => "Non Face to Face", 'risk_score' => 3 ],
        [ 'value'=> "Online/Social Media Portal", 'label' => "Online/Social Media Portal", 'risk_score' => 4 ],
        [ 'value'=> "Thirdparty Referal", 'label' => "Thirdparty Referral", 'risk_score' => 4 ], 
    ];
    public const BUSINESS_ACTIVITIES = [
        [ 'value' => "Accounting/Auditing Firm", 'label' => "Accounting/Auditing Firm", 'risk_score' => 1],
        [ 'value' => "Bank/Financial Institute", 'label' => "Bank/Financial Institute", 'risk_score' => 1],
        [ 'value' => "DPMS - Retail Store", 'label' => "DPMS - Retail Store", 'risk_score' => 3],
        [ 'value' => "DPMS - Bullion Wholesale", 'label' => "DPMS - Bullion Wholesale", 'risk_score' => 3],
        [ 'value' => "DPMS - Mining, Refining", 'label' => "DPMS - Mining, Refining", 'risk_score' => 3],
        [ 'value' => "DPMS- Factory, Workshop, Goldsmith", 'label' => "DPMS- Factory, Workshop, Goldsmith", 'risk_score' => 3],
        [ 'value' => "Real Estate", 'label' => "Real Estate", 'risk_score' => 4],
        [ 'value' => "General Trading", 'label' => "General Trading", 'risk_score' => 1],
        [ 'value' => "Law Firm", 'label' => "Law Firm", 'risk_score' => 1],
        [ 'value' => "Education", 'label' => "Education", 'risk_score' => 1],
        [ 'value' => "Other", 'label' => "Other", 'risk_score' => 1]
    ];
    public const DELIVERY_CHANNEL = [
        [ 'value' => "Face to Face", 'label' => "Face to Face", 'risk_score' => 1 ],
        [ 'value' => "Non Face to Face", 'label' => "Non Face to Face", 'risk_score' => 4 ],
    ];

    /**
     * Calculate customer risk level (1-5)
     * 
     * @param array $productIds Array of product IDs
     * @param string|null $country Country name
     * @return int Risk level (1-5)
     */
    public function calculateIndividualRiskLevel(array $productIds, ?string $country = null, ?string $occupation = null, ?string $sourceOfIncome = null, ?string $paymentMode = null, ?string $modeOfApproach = null, ?bool $pep = null)
    {
        // 1. Geography Risk (Weight: 25%)
        $geoScore = $this->calculateCountryRisk($country);

        // 2. Product Risk (Weight: 20%)
        $productScore = $this->calculateProductRisk($productIds, $paymentMode, self::PAYMENT_METHODS);

        // 3. Delivery Channel Risk (Weight: 15%)
        $channelScore = $this->getScoreFromList($modeOfApproach, self::MODE_OF_APPROACH);

        // 4. Customer Risk (Weight: 40%) - Average of attributes as per Image A
        $occScore = $this->getScoreFromList($occupation, self::OCCUPATION);
        $soiScore = $this->getScoreFromList($sourceOfIncome, self::SOURCE_OF_INCOME);
        $pepScore = $pep ? 4 : 1; // Domestic PEP = 4 from image
        
        // Ownership Structure: If owner/director, score is 5 from image
        // $ownershipScore = ($occupation === 'Owner/Partner/Director') ? 5 : 1;

        $customerAvg = ($occScore + $soiScore + $pepScore) / 3;

        // Weighted Calculation as per Image 4
        // Score = (Customer * 0.40) + (Geography * 0.25) + (Product * 0.20) + (Channel * 0.15)
        $totalWeightedScore = ($customerAvg * 0.40) + ($geoScore * 0.25) + ($productScore * 0.20) + ($channelScore * 0.15);

        // Return rounded result (1-5)
        Log::info("Individual Risk Calculation: CustomerAvg={$customerAvg}, GeoScore={$geoScore}, ProductScore={$productScore}, ChannelScore={$channelScore}, TotalWeightedScore={$totalWeightedScore}");
        return  $totalWeightedScore;
    }

    public function calculateProductRisk(array $productIds, ?string $value = null, array $list)
    {
        if (empty($productIds)) return 1.0;

        $riskLevels = Product::whereIn('id', $productIds)
            ->pluck('risk_level')
            ->filter(fn($r) => $r !== null)
            ->all();

        if (empty($riskLevels)) return 1.0;

        // Using average for product risk
        $productRisk = array_sum($riskLevels) / count($riskLevels);

        $paymentRisk = $this->getScoreFromList($value, $list);
        if($value)
        {
            return ($productRisk + $paymentRisk) / 2;
        }

        return $productRisk;
    }

    public function calculateCountryRisk(?string $country)
    {
        if (!$country) return 1;

        $found = collect(self::HIGH_RISK_COUNTRIES)
            ->first(fn($c) => strcasecmp(trim($c['label']), trim($country)) === 0);

        if (!$found) return 1;

        return $found['risk_score'];
    }

    public function getScoreFromList(?string $value, array $list)
    {
        if (!$value) return 1;
        $item = collect($list)->firstWhere('value', $value);
        return $item ? $item['risk_score'] : 1;
    }

    public function getHighRiskCountries()
    {
        return self::HIGH_RISK_COUNTRIES;
    }

    public function calculateCorporateRiskLevel(array $corporatePersons, ?string $businessActivity = null, ?string $country = null, ?array $productIds, ?string $paymentMode = null, ?string $deliveryChannel = null)
    {
        $ownershipScore = $this->ownershipRisk($corporatePersons);
        $businessActivityScore = $this->getScoreFromList($businessActivity,self::BUSINESS_ACTIVITIES);
        $countryIncorporateScore = $this->getScoreFromList($country,self::HIGH_RISK_COUNTRIES);
        $productScore = $this->calculateProductRisk($productIds, $paymentMode, self::PAYMENT_METHODS);
        $channelScore = $this->getScoreFromList($deliveryChannel, self::DELIVERY_CHANNEL);
        
        $totalWeightedScore = ($ownershipScore * 0.30) + ($businessActivityScore * 0.20) + ($countryIncorporateScore * 0.20) + ($productScore * 0.15) + ($channelScore * 0.15);

        return  $totalWeightedScore;
    }
    public function ownershipRisk(array $persons){
        $personCount = count($persons);
        if($personCount == 0) return 1;
        elseif($personCount == 1)
        {
            if($persons[0]['nationality'] == "United Arab Emirates") return 1;
            else return 2;
        }
        elseif($personCount == 2)
        {
            if($persons[0]['nationality'] != "United Arab Emirates" || $persons[1]['nationality'] != "United Arab Emirates") return 3;
            elseif($persons[0]['nationality'] == "United Arab Emirates" && $persons[1]['nationality'] == "United Arab Emirates") return 2;
            elseif($persons[0]['nationality'] != "United Arab Emirates" && $persons[1]['nationality'] != "United Arab Emirates") return 3;
        }
        elseif($personCount > 2)
        {
            foreach($persons as $per){
                if($per['nationality'] != "United Arab Emirates") return 5;
            }
        }
        return 1;
    }
}