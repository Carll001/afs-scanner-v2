<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;

class DocxTemplateService
{
    /**
     * @param array<string, string> $rowData
     */
    public function render(string $templatePath, string $outputPath, array $rowData): void
    {
        $processor = new TemplateProcessor($templatePath);
        if (method_exists($processor, 'setMacroChars')) {
            $processor->setMacroChars('{', '}');
        } else {
            $processor->setMacroOpeningChars('{');
            $processor->setMacroClosingChars('}');
        }

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
