<?php

namespace Tests\Unit;

use App\Services\DocxTemplateService;
use DOMDocument;
use DOMXPath;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class DocxTemplateServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'docx-template-service-'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_render_keeps_direct_2025_placeholder_in_non_2025_template(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('direct-2025.docx', [
            'NET INCOME 2025',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME' => '20',
            'NET INCOME 2025' => '30',
        ]);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([], $validation['errors']);

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'direct-2025-output.docx';
        $service->render($templatePath, $outputPath, [
            'NET INCOME' => '20',
            'NET INCOME 2025' => '30',
        ]);

        $this->assertStringContainsString('30.00', $this->readDocumentXml($outputPath));
    }

    public function test_render_auto_sums_2025_placeholder_in_2025_template(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('auto-sum-2025.docx', [
            'NET INCOME',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME' => '20',
            'NET INCOME 2025' => '30',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([], $validation['errors']);

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'auto-sum-2025-output.docx';
        $service->render($templatePath, $outputPath, [
            'NET INCOME' => '20',
            'NET INCOME 2025' => '30',
        ], 2025);

        $this->assertStringContainsString('50.00', $this->readDocumentXml($outputPath));
    }

    public function test_render_keeps_plain_placeholder_direct_in_2025_template_without_2025_companion_header(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('direct-base-2025-template.docx', [
            'NET INCOME',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME' => '20',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([], $validation['errors']);

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'direct-base-2025-template-output.docx';
        $service->render($templatePath, $outputPath, [
            'NET INCOME' => '20',
        ], 2025);

        $this->assertStringContainsString('20.00', $this->readDocumentXml($outputPath));
    }

    public function test_render_resolves_subtraction_formula_placeholder_in_2025_template(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('subtraction.docx', [
            'TRADE RECEIVABLES 2025-TRADE RECEIVABLES',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'TRADE RECEIVABLES' => '20',
            'TRADE RECEIVABLES 2025' => '120',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([], $validation['errors']);

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'subtraction-output.docx';
        $service->render($templatePath, $outputPath, [
            'TRADE RECEIVABLES' => '20',
            'TRADE RECEIVABLES 2025' => '120',
        ], 2025);

        $this->assertStringContainsString('100.00', $this->readDocumentXml($outputPath));
    }

    public function test_validate_row_data_reports_missing_base_header_for_2025_auto_sum(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('missing-base-header.docx', [
            'NET INCOME',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME 2025' => '30',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([
            'Placeholder {NET INCOME} requires the "NET INCOME" header.',
        ], $validation['errors']);
    }

    public function test_validate_row_data_reports_blank_2025_value_for_auto_sum(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('blank-auto-sum-value.docx', [
            'NET INCOME',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME' => '20',
            'NET INCOME 2025' => '   ',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([
            'Placeholder {NET INCOME} requires a numeric value for "NET INCOME 2025".',
        ], $validation['errors']);
    }

    public function test_validate_row_data_reports_non_numeric_base_value_for_auto_sum(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('non-numeric-base-auto-sum.docx', [
            'NET INCOME',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'NET INCOME' => 'abc',
            'NET INCOME 2025' => '30',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([
            'Placeholder {NET INCOME} requires a numeric value for "NET INCOME".',
        ], $validation['errors']);
    }

    public function test_validate_row_data_reports_missing_subtraction_operand_header(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('missing-subtraction-header.docx', [
            'TRADE RECEIVABLES 2025-TRADE RECEIVABLES',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'TRADE RECEIVABLES 2025' => '120',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([
            'Placeholder {TRADE RECEIVABLES 2025-TRADE RECEIVABLES} requires the "TRADE RECEIVABLES" header.',
        ], $validation['errors']);
    }

    public function test_validate_row_data_reports_invalid_subtraction_placeholder_in_2025_template(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('direct-match.docx', [
            'TRADE RECEIVABLES 2025-TRADE RECEIVABLES-OTHER',
        ]);

        $validation = $service->validateRowData($templatePath, [
            'TRADE RECEIVABLES 2025' => '120',
            'TRADE RECEIVABLES' => '20',
            'OTHER' => '10',
        ], 2025);

        $this->assertSame([], $validation['missing_data']);
        $this->assertSame([
            'Invalid subtraction placeholder {TRADE RECEIVABLES 2025-TRADE RECEIVABLES-OTHER}. Expected exactly two headers with a single - operator.',
        ], $validation['errors']);
    }

    public function test_render_repairs_split_placeholder_runs_and_preserves_table_layout(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createFinancialPositionTableTemplate('split-placeholder-table.docx');
        $this->splitPlaceholderAcrossRuns($templatePath, 'SHE');

        $expectedLayout = $this->tableLayoutSignature($templatePath);

        $this->assertContains('SHE', $service->placeholderKeys($templatePath));

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'split-placeholder-table-output.docx';
        $service->render($templatePath, $outputPath, [
            'SHE' => '-1334344.887',
            'TOTAL LIAB AND SHE' => '-868529.9563',
        ]);

        $this->assertStringContainsString('-1,334,344.89', $this->readDocumentXml($outputPath));
        $this->assertStringContainsString('-868,529.96', $this->readDocumentXml($outputPath));
        $this->assertSame($expectedLayout, $this->tableLayoutSignature($outputPath));
    }

    public function test_render_matches_curly_quote_placeholder_to_ascii_row_data_key(): void
    {
        $service = new DocxTemplateService;
        $templatePath = $this->createTemplate('curly-quote.docx', [
            "President’s Name",
        ]);

        $outputPath = $this->tempDir.DIRECTORY_SEPARATOR.'curly-quote-output.docx';
        $service->render($templatePath, $outputPath, [
            "President's Name" => 'Windelyn Naoe Baltazar',
        ]);

        $this->assertStringContainsString('Windelyn Naoe Baltazar', $this->readDocumentXml($outputPath));
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function createTemplate(string $filename, array $placeholders): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        foreach ($placeholders as $placeholder) {
            $section->addText('{'.$placeholder.'}');
        }

        $path = $this->tempDir.DIRECTORY_SEPARATOR.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function readDocumentXml(string $docxPath): string
    {
        $zip = new ZipArchive;
        $result = $zip->open($docxPath);

        $this->assertTrue($result === true, 'The generated DOCX file could not be opened.');

        $contents = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($contents);

        return $contents;
    }

    private function createFinancialPositionTableTemplate(string $filename): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $table = $section->addTable();

        $table->addRow();
        $table->addCell(4111)->addText('OWNER’S EQUITY');
        $table->addCell(992)->addText('');
        $table->addCell(4257)->addText('{SHE}');

        $table->addRow();
        $table->addCell(4111)->addText('TOTAL LIABILITIES AND EQUITY');
        $table->addCell(992)->addText('');
        $table->addCell(4257)->addText('{TOTAL LIAB AND SHE}');

        $path = $this->tempDir.DIRECTORY_SEPARATOR.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function splitPlaceholderAcrossRuns(string $docxPath, string $placeholder): void
    {
        $xml = $this->readDocumentXml($docxPath);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $this->assertTrue($document->loadXML($xml));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $targetText = '{'.$placeholder.'}';
        $targetNode = null;
        foreach ($xpath->query('//w:t') ?: [] as $textNode) {
            if ($textNode->textContent === $targetText) {
                $targetNode = $textNode;

                break;
            }
        }

        $this->assertNotNull($targetNode, "Placeholder {$targetText} was not found in the DOCX template.");

        $runNode = $targetNode->parentNode;
        $paragraphNode = $runNode?->parentNode;

        $this->assertNotNull($runNode);
        $this->assertNotNull($paragraphNode);

        foreach (['{', $placeholder, '}'] as $part) {
            $clonedRun = $runNode->cloneNode(true);
            $clonedTextNode = ($xpath->query('.//w:t', $clonedRun) ?: [])->item(0);

            $this->assertNotNull($clonedTextNode);
            $clonedTextNode->nodeValue = $part;

            $paragraphNode->insertBefore($clonedRun, $runNode);
        }

        $paragraphNode->removeChild($runNode);

        $this->replaceDocumentXml($docxPath, $document->saveXML() ?: $xml);
    }

    /**
     * @return array{grid: list<string>, cells: list<string>}
     */
    private function tableLayoutSignature(string $docxPath): array
    {
        $xml = $this->readDocumentXml($docxPath);

        preg_match('/<w:tblGrid>(.*?)<\/w:tblGrid>/s', $xml, $gridMatch);
        preg_match_all('/<w:gridCol[^>]*w:w="(\d+)"/', $gridMatch[0] ?? '', $gridWidths);
        preg_match_all('/<w:tcW[^>]*w:w="(\d+)"/', $xml, $cellWidths);

        return [
            'grid' => $gridWidths[1] ?? [],
            'cells' => array_slice($cellWidths[1] ?? [], 0, 6),
        ];
    }

    private function replaceDocumentXml(string $docxPath, string $xml): void
    {
        $zip = new ZipArchive;
        $result = $zip->open($docxPath);

        $this->assertTrue($result === true, 'The DOCX template file could not be opened for editing.');
        $this->assertTrue($zip->deleteName('word/document.xml'));
        $this->assertTrue($zip->addFromString('word/document.xml', $xml));
        $zip->close();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$item;

            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);

                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
