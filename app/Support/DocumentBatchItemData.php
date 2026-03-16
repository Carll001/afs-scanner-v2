<?php

namespace App\Support;

class DocumentBatchItemData
{
    /**
     * @param  array<string, mixed>  $rowData
     */
    public static function extractCompany(array $rowData): string
    {
        $fallback = '';

        foreach ($rowData as $key => $value) {
            $normalizedKey = self::normalizeCompanyKey((string) $key);
            $stringValue = is_scalar($value) ? trim((string) $value) : '';

            if ($normalizedKey === 'company') {
                return $stringValue;
            }

            if ($fallback === '' && str_contains($normalizedKey, 'company')) {
                $fallback = $stringValue;
            }
        }

        return $fallback;
    }

    private static function normalizeCompanyKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
