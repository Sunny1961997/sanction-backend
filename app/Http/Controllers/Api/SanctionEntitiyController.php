<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SanctionEntity;
use App\Models\ScreeningSubject;
use App\Models\TestEntity;
use Illuminate\Http\Request;

class SanctionEntitiyController extends Controller
{

    public function index(Request $request)
    {
        $limit = max(1, (int) $request->input('limit', 50));
        $offsetInput = (int) $request->input('offset', 1);
        $offset = max(0, $offsetInput - 1);

        $searchName = trim((string) $request->input('search', ''));
        if ($searchName === '') {
            return response()->json(['message' => 'search is required'], 422);
        }

        $subjectTypeInput = strtolower(trim((string) $request->input('subject_type', 'individual'))); // individual|entity|vessel
        $dobInput = trim((string) $request->input('birth_date', ''));
        $genderInput = strtolower(trim((string) $request->input('gender', '')));
        $countryInput = trim((string) $request->input('country', $request->input('nationality', '')));
        $addressInput = trim((string) $request->input('address', ''));
        $sourceInput = $request->input('source'); // string or array
        $sourceImo = trim((string) $request->input('imo', ''));
        $confidenceRating = (float) $request->input('confidence_rating', 0.0);

        // Filter only by "hard" constraints (optional)
        $filters = [];

        // Subject type mapping to stored values
        if ($subjectTypeInput === 'individual') {
            $filters[] = 'subject_type IN ["Individual","Person","individual","person"]';
        } elseif ($subjectTypeInput === 'entity') {
            $filters[] = 'subject_type IN ["Entity","Organization","Enterprise"]';
        } elseif ($subjectTypeInput === 'vessel') {
            $filters[] = 'subject_type IN ["Ship","Vessel", "Enterprise", "enterprise", "ship","vessel"]';
        }

        if ($sourceInput) {
            $sources = is_array($sourceInput) ? $sourceInput : [$sourceInput];
            $sources = array_values(array_filter(array_map('trim', $sources)));
            if (!empty($sources)) {
                $quoted = array_map(fn($s) => '"' . addslashes($s) . '"', $sources);
                $filters[] = 'source IN [' . implode(',', $quoted) . ']';
            }
        }

        // Never return whitelisted (optional)
        if ($request->boolean('exclude_whitelisted', true)) {
            $filters[] = 'is_whitelisted = false';
        }

        $filterString = !empty($filters) ? implode(' AND ', $filters) : null;

        // Pull more candidates than page size, then score and paginate in PHP
        $candidateLimit = min(500, max(100, $limit * 10));

        $candidates = ScreeningSubject::search($searchName, function ($meilisearch, $query, $options) use ($filterString, $candidateLimit) {
            if ($filterString) $options['filter'] = $filterString;
            $options['limit'] = $candidateLimit;
            $options['offset'] = 0;
            $options['matchingStrategy'] = 'last';
            return $meilisearch->search($query, $options);
        })->get();

        $weights = $this->weightsFor($subjectTypeInput);

        $sourcePriority = ['CANADA', 'UAE', 'UN', 'UK', 'OFAC', 'EU'];
        $sourceRank = array_flip($sourcePriority);

        $scored = $candidates->map(function ($row) use (
            $weights,
            $searchName,
            $dobInput,
            $genderInput,
            $countryInput,
            $addressInput,
            $sourceImo,
            $sourceRank
        ) {
            $scoreParts = [];

            $scoreParts['name'] = $this->scoreName($searchName, (string) $row->name, $weights['name']);

            $scoreParts['dob'] = $this->scoreDob($dobInput, (string) ($row->dob ?? ''), $weights['dob']);

            $scoreParts['country'] = $this->scoreCountry($countryInput, (string) ($row->nationality ?? ''), $weights['country']);

            $scoreParts['gender'] = $this->scoreGender($genderInput, (string) ($row->gender ?? ''), $weights['gender']);

            $scoreParts['address'] = $this->scoreAddress($addressInput, (string) ($row->address ?? ''), $weights['address']);

            $scoreParts['imo'] = $this->scoreImo($sourceImo, (string) ($row->other_information ?? ''), $weights['imo'] ?? 0);

            $total = array_sum($scoreParts);

            $src = (string) ($row->source ?? '');
            $priority = $sourceRank[$src] ?? 999;

            return [
                'id' => $row->id,
                'source' => $src,
                'subject_type' => $row->subject_type,
                'name' => $row->name,
                'dob' => $row->dob,
                'gender' => $row->gender,
                'nationality' => $row->nationality,
                'address' => $row->address,
                'confidence' => round($total, 2),
                'breakdown' => array_map(fn($v) => round($v, 2), $scoreParts),
                '_source_priority' => $priority,
            ];
        });

        $sorted = $scored->sort(function ($a, $b) {
            if ($a['confidence'] === $b['confidence']) {
                return $a['_source_priority'] <=> $b['_source_priority'];
            }
            return $b['confidence'] <=> $a['confidence'];
        })->values();

        $filtered = $sorted->filter(function ($item) use ($confidenceRating) {
            return ($item['confidence'] ?? 0) <= $confidenceRating;
        })->values();

        // Use paged results for "data" (and remove _source_priority)
        $paged = $filtered->slice($offset, $limit)->values()->map(function ($r) {
            unset($r['_source_priority']);
            return $r;
        });


        $wantedSources = ['CANADA', 'UAE', 'UN', 'UK', 'OFAC', 'EU'];
        $perSourceLimit = 1;

        // Top N matches per source (map: source => [match1, match2] with nulls if missing)
        $bestBySourceMap = [];
        foreach ($wantedSources as $src) {
            $top = $filtered
                ->where('source', $src)
                ->take($perSourceLimit)
                ->values()
                ->map(function ($r) {
                    unset($r['_source_priority']);
                    return $r;
                })
                ->all();

            // ensure fixed size (always 1)
            while (count($top) < $perSourceLimit) {
                $top[] = null;
            }

            $bestBySourceMap[$src] = $top;
        }

        // Build list that ALWAYS contains all sources, sorted by best confidence (nulls last)
        $bestBySource = collect($wantedSources)
            ->map(function ($src) use ($bestBySourceMap) {
                $items = $bestBySourceMap[$src] ?? [null];

                // best confidence is from first item if exists
                $bestConfidence = (is_array($items[0] ?? null) && isset($items[0]['confidence']))
                    ? (float) $items[0]['confidence']
                    : null;

                return [
                    'source' => $src,
                    'best_confidence' => $bestConfidence,
                    'data' => $items, // [match1] (can be null)
                ];
            })
            ->sort(function ($a, $b) {
                $ac = $a['best_confidence'];
                $bc = $b['best_confidence'];

                if ($ac === null && $bc === null) return 0;
                if ($ac === null) return 1;
                if ($bc === null) return -1;

                return $bc <=> $ac; // higher first
            })
            ->values();

        $isMatch = $filtered->isNotEmpty();

        // Log screening via ScreeningLogController
        $logController = new ScreeningLogController();
        $logRequest = Request::create('/api/screening-logs', 'POST', [
            'user_id' => $request->user()->id,
            'search_string' => $searchName,
            'screening_type' => $subjectTypeInput,
            'is_match' => $isMatch,
        ]);
        $logRequest->setUserResolver(fn() => $request->user());
        $logController->store($logRequest);

        return response()->json([
            "status" => "success",
            "message" => "Found screening results.",
            "data" => [
                'searched_for' => $searchName,
                'confidence_threshold' => $confidenceRating,
                'total_candidates' => $candidates->count(),
                'filtered_results' => $filtered->count(),
                'best_by_source' => $bestBySource,
            ]
        ]);
    }

    private function weightsFor(string $subjectTypeInput): array
    {
        return match ($subjectTypeInput) {
            'entity' => ['name' => 50, 'dob' => 0,  'country' => 30, 'gender' => 0,  'address' => 20],
            // vessel: country 25% + imo 25% (and keep name 50%)
            'vessel' => ['name' => 50, 'dob' => 0,  'country' => 25, 'gender' => 0,  'address' => 0, 'imo' => 25],
            default  => ['name' => 50, 'dob' => 20, 'country' => 20, 'gender' => 10, 'address' => 0],
        };
    }

    private function scoreImo(string $inputImo, string $otherInformation, float $weight): float
    {
        if ($weight <= 0) return 0.0;

        $imo = trim((string) $inputImo);
        if ($imo === '') return 0.0;

        $hay = mb_strtolower((string) $otherInformation);
        if ($hay === '') return 0.0;

        // only attempt match if there is any "imo" mention
        if (!str_contains($hay, 'imo')) return 0.0;

        // normalize IMO to digits only (common: "5342883", "IMO 5342883", "imo:5342883")
        $imoDigits = preg_replace('/\D+/', '', $imo);
        if ($imoDigits === '') return 0.0;

        // If digits appear anywhere after normalization, award full IMO weight
        $hayDigits = preg_replace('/\D+/', '', $hay);

        return str_contains($hayDigits, $imoDigits) ? $weight : 0.0;
    }

    private function normalizeText(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return $s;
    }

    private function scoreName(string $query, string $candidate, float $weight): float
    {
        $q = $this->normalizeText($query);
        $c = $this->normalizeText($candidate);
        if ($q === '' || $c === '') return 0.0;
        if ($q === $c) return $weight;

        similar_text($q, $c, $pct);
        $ratio = max(0.0, min(1.0, $pct / 100.0));

        return $weight * $ratio;
    }

    private function normalizeDob(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = preg_replace('/[\.\/\s]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    }

    private function extractYear(string $dob): ?string
    {
        if (preg_match('/\b(\d{4})\b/', $dob, $m)) return $m[1];
        return null;
    }

    private function extractYears(string $s): array
    {
        // returns unique 4-digit years found in the string
        if ($s === '') return [];
        preg_match_all('/\b(18|19|20)\d{2}\b/', $s, $m);
        $years = $m[0] ?? [];
        $years = array_values(array_unique($years));
        return $years;
    }

    private function scoreDob(string $inputDob, string $candidateDob, float $weight): float
    {
        if ($weight <= 0) return 0.0;

        $inRaw = trim((string) $inputDob);
        $candRaw = trim((string) $candidateDob);

        if ($inRaw === '' || $candRaw === '') return 0.0;

        $in = $this->normalizeDob($inRaw);
        $cand = $this->normalizeDob($candRaw);

        // Full match => 100% of DOB weight
        if ($in !== '' && $cand !== '' && $in === $cand) {
            return $weight;
        }

        // Year-only partial match
        $inYears = $this->extractYears($inRaw . ' ' . $in);
        $candYears = $this->extractYears($candRaw . ' ' . $cand);

        if (!empty($inYears) && !empty($candYears)) {
            $common = array_intersect($inYears, $candYears);

            // If any year matches, give partial credit.
            // Tune these multipliers as you like:
            // - 0.6 means a year match gives 60% of DOB weight (e.g., 12 points out of 20)
            if (!empty($common)) {
                return $weight * 0.6;
            }
        }

        // If input is just "59" or other short fragments, try a loose substring match
        // (very conservative to avoid false positives)
        if (mb_strlen($inRaw) >= 4 && str_contains($candRaw, $inRaw)) {
            return $weight * 0.4;
        }

        return 0.0;
    }

    private function normalizeGender(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return match ($s) {
            'm', 'male' => 'male',
            'f', 'female' => 'female',
            default => '',
        };
    }

    private function scoreGender(string $inputGender, string $candidateGender, float $weight): float
    {
        if ($weight <= 0) return 0.0;

        $in = $this->normalizeGender($inputGender);
        $cand = $this->normalizeGender($candidateGender);

        if ($in === '' || $cand === '') return 0.0;
        return ($in === $cand) ? $weight : 0.0;
    }

    private function scoreCountry(string $inputCountry, string $nationality, float $weight): float
    {
        if ($weight <= 0) return 0.0;

        $in = $this->normalizeText($inputCountry);
        if ($in === '') return 0.0;

        $nat = $this->normalizeText($nationality);
        if ($nat === '') return 0.0;

        $parts = preg_split('/[\/,;|]+/', $nat) ?: [$nat];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        foreach ($parts as $p) {
            if ($p === $in) return $weight;
        }

        $bestRatio = 0.0;
        foreach ($parts as $p) {
            similar_text($in, $p, $pct);
            $bestRatio = max($bestRatio, $pct / 100.0);
        }

        if ($bestRatio < 0.5) return 0.0;
        return $weight * $bestRatio;
    }

    private function scoreAddress(string $inputAddress, string $candidateAddress, float $weight): float
    {
        if ($weight <= 0) return 0.0;

        $in = $this->normalizeText($inputAddress);
        $cand = $this->normalizeText($candidateAddress);

        if ($in === '' || $cand === '') return 0.0;
        if ($in === $cand) return $weight;

        similar_text($in, $cand, $pct);
        $ratio = max(0.0, min(1.0, $pct / 100.0));

        if ($ratio < 0.5) return 0.0;
        return $weight * $ratio;
    }
    public function countries(Request $request)
    {
        $countries = [];
        $countriesPath = resource_path('json/countries.json');

        if (file_exists($countriesPath)) {
            $countries = json_decode(file_get_contents($countriesPath), true);
        }
        return response()->json([
            'data' => $countries,
        ]);
    }
    public function show($id)
    {
        $entity = ScreeningSubject::find($id);
        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Screening subject not found',
                'data' => null,
            ], 404);
        }
        return response()->json([
            'status' => 'success',
            'message' => 'Screening subject retrieved successfully',
            'data' => $entity,
        ]);
    }
}
