<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;

class DocxTemplateService
{
    /**
     * @return list<string>
     */
    public function placeholderKeys(string $templatePath): array
    {
        $processor = new TemplateProcessor($templatePath);
        $this->configureMacroChars($processor);

        return $this->extractPlaceholderKeys($processor);
    }

    /**
     * @param array<string, string> $rowData
     * @return array{missing_data: list<string>}
     */
    public function validateRowData(string $templatePath, array $rowData): array
    {
        $normalizedMap = [];
        foreach ($rowData as $key => $value) {
            $normalizedMap[$this->normalizeHeader($key)] = $value;
        }

        $missingData = [];

        foreach ($this->placeholderKeys($templatePath) as $key) {
            $normalizedKey = $this->normalizeHeader($key);
            if (! array_key_exists($normalizedKey, $normalizedMap)) {
                continue;
            }

            if (trim($normalizedMap[$normalizedKey]) === '') {
                $missingData[] = $key;
            }
        }

        return [
            'missing_data' => array_values(array_unique($missingData)),
        ];
    }

    /**
     * @param array<string, string> $rowData
     */
    public function render(string $templatePath, string $outputPath, array $rowData): void
    {
        $processor = new TemplateProcessor($templatePath);
        $this->configureMacroChars($processor);

        $normalizedMap = [];
        foreach ($rowData as $key => $value) {
            $normalizedMap[$this->normalizeHeader($key)] = $this->formatValueForTemplate($value);
        }

        $keysInTemplate = $this->extractPlaceholderKeys($processor);
        foreach ($keysInTemplate as $key) {
            $processor->setValue($key, $normalizedMap[$this->normalizeHeader($key)] ?? '');
        }

        $processor->saveAs($outputPath);
    }

    private function configureMacroChars(TemplateProcessor $processor): void
    {
        if (method_exists($processor, 'setMacroChars')) {
            $processor->setMacroChars('{', '}');
        } else {
            $processor->setMacroOpeningChars('{');
            $processor->setMacroClosingChars('}');
        }
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholderKeys(TemplateProcessor $processor): array
    {
        $values = $processor->getVariables();

        return array_values(array_unique(array_map('trim', $values)));
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));

        return preg_replace('/\s+/', '_', $normalized) ?? $normalized;
    }

    private function formatValueForTemplate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        $numeric = str_replace([',', ' '], '', $trimmed);
        if (! is_numeric($numeric)) {
            return $value;
        }

        return number_format((float) $numeric, 2, '.', ',');
    }
}
