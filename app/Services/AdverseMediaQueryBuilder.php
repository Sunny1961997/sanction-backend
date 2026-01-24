<?php
namespace App\Services;
class AdverseMediaQueryBuilder
{
    public static function build(
        string $name,
        string $mode = 'combined',
        ?string $country = null
    ): string {
        $keywords = include app_path('Support/AdverseKeywords.php');

        // Exact name match
        $query = "\"{$name}\"";

        switch ($mode) {

            case 'exact':
                // Just exact name
                $query .= ' AND (news OR interview OR statement)';
                break;

            case 'adverse':
                $query .= ' AND (' . self::orGroup($keywords['adverse']) . ')';
                break;

            case 'news':
                $query .= ' AND (' . self::siteGroup($keywords['news_sites']) . ')';
                break;

            case 'social':
                $query .= ' AND (' . self::orGroup($keywords['social']) . ')';
                break;

            case 'combined':
            default:
                $query .= ' AND (' . self::orGroup($keywords['adverse']) . ')';
                $query .= ' AND (' . self::siteGroup($keywords['news_sites']) . ')';
                break;
        }

        if ($country) {
            $query .= " AND {$country}";
        }

        // Remove obvious noise
        $query .= ' -movie -song -fiction';

        return $query;
    }

    private static function orGroup(array $terms): string
    {
        return implode(' OR ', array_map(fn($t) => "\"{$t}\"", $terms));
    }

    private static function siteGroup(array $sites): string
    {
        return implode(' OR ', array_map(fn($s) => "site:{$s}", $sites));
    }
}