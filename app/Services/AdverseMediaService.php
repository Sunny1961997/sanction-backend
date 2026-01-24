<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;

// class AdverseMediaService
// {
//     public function search(
//         string $name,
//         string $mode = 'combined',
//         ?string $country = null
//     ): array {

//         $query = AdverseMediaQueryBuilder::build($name, $mode, $country);

//         return Http::get(
//             'https://www.googleapis.com/customsearch/v1',
//             [
//                 'key' => env('GOOGLE_API_KEY'),
//                 'cx'  => env('GOOGLE_CSE_ID'),
//                 'q'   => $query,
//                 'num' => 5,
//             ]
//         )->json();
//     }
// }
class AdverseMediaService
{
    public function search(
        string $name,
        string $mode = 'combined',
        ?string $country = null
    ): array {

        $query = AdverseMediaQueryBuilder::build($name, $mode, $country);

        return Http::get(
            'https://www.googleapis.com/customsearch/v1',
            [
                'key' => config('services.google.api_key'),
                'cx'  => config('services.google.cx'),
                'q'   => $query,
                'num' => 5,          
                'hl'  => 'en',
                'gl'  => 'us',
            ]
        )->json();
    }
}