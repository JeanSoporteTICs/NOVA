<?php

namespace Tests\Unit;

use App\Modulos\Procedimientos\Services\BlankOfficeDocumentFactory;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BlankOfficeDocumentFactoryTest extends TestCase
{
    public function test_it_builds_valid_word_and_excel_payloads(): void
    {
        $factory = new BlankOfficeDocumentFactory();

        $word = $factory->create('Procedimiento seguridad.docx', 'DOCX');
        $excel = $factory->create('Inventario', 'xlsx');

        $this->assertSame('Procedimiento seguridad.docx', $word['name']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $word['mime']);
        $this->assertStringStartsWith("PK\x03\x04", $word['binary']);
        $this->assertSame('Inventario.xlsx', $excel['name']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $excel['mime']);
        $this->assertStringStartsWith("PK\x03\x04", $excel['binary']);
        $this->assertOfficeArchiveContains($word['binary'], 'word/document.xml');
        $this->assertOfficeArchiveContains($excel['binary'], 'xl/worksheets/sheet1.xml');
    }

    public function test_it_rejects_unsupported_types(): void
    {
        $this->assertNull((new BlankOfficeDocumentFactory())->create('Archivo', 'exe'));
    }

    public function test_procedimientos_browser_exposes_the_creation_flow(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineMantencion/views/Procedimientos/_nc_browser.php');
        $controller = file_get_contents($root.'/RedmineMantencion/controllers/nc_browser.php');

        $this->assertStringContainsString('id="nc-create-office-btn"', $view);
        $this->assertStringContainsString('id="ncCreateDocx"', $view);
        $this->assertStringContainsString('id="ncCreateXlsx"', $view);
        $this->assertStringContainsString("action: 'create_office'", $view);
        $this->assertStringContainsString("case 'create_office':", $controller);
    }

    private function assertOfficeArchiveContains(string $binary, string $expectedEntry): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nova-office-test-');
        $this->assertIsString($path);
        file_put_contents($path, $binary);

        try {
            $archive = new ZipArchive();
            $this->assertTrue($archive->open($path) === true);
            $this->assertNotFalse($archive->locateName('[Content_Types].xml'));
            $this->assertNotFalse($archive->locateName($expectedEntry));
            $archive->close();
        } finally {
            @unlink($path);
        }
    }
}
