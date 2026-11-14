<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\SourceError;
use PolygonLab\PlateformesAgreees\Outils\XlsxReader;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

final class XlsxReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/lecteur-essai-' . bin2hex(random_bytes(6)) . '.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsSharedInlineAndNumericCells(): void
    {
        XlsxBuilder::write($this->file, [
            ['Titre'],
            [],
            ['Raison sociale', null, 'inline:Chaîne en ligne', 111111118, 'num:1.11111118E8', 'num:12.5'],
            ['Exemple & Fils « SAS »'],
        ], 'Plateformes');

        $book = XlsxReader::read($this->file, 'essai.xlsx');

        self::assertSame('Plateformes', $book['sheet']);
        self::assertSame(1, $book['sheets']);
        self::assertSame([1, 3, 4], array_keys($book['rows']));
        self::assertSame([0 => 'Raison sociale', 2 => 'Chaîne en ligne', 3 => '111111118', 4 => '111111118', 5 => '12.5'], $book['rows'][3]);
        self::assertSame([0 => 'Exemple & Fils « SAS »'], $book['rows'][4]);
    }

    public function testReadsMergedRanges(): void
    {
        XlsxBuilder::file($this->file, [XlsxBuilder::platform('Alpha Facturation')]);

        $book = XlsxReader::read($this->file, 'essai.xlsx');

        self::assertSame([[1, 0, 2, 0], [1, 1, 1, 3], [1, 4, 2, 4], [1, 5, 2, 5], [1, 6, 2, 6]], $book['merges'], 'plages A1:A2, B1:D1, E1:E2, F1:F2, G1:G2');
        self::assertSame("Adresse\nde l\u{2019}établis-\nsement principal", $book['rows'][1][1], 'sauts de ligne conservés à la lecture');
        self::assertArrayNotHasKey(2, $book['rows'][1], 'C1 vide : la valeur est dans la première cellule de la plage');
        self::assertSame('46002', $book['rows'][3][6], 'date en nombre de série');
    }

    public function testColumnLettersAndIndexes(): void
    {
        self::assertSame(0, XlsxReader::columnIndex('A1'));
        self::assertSame(2, XlsxReader::columnIndex('C12'));
        self::assertSame(27, XlsxReader::columnIndex('AB3'));
        self::assertNull(XlsxReader::columnIndex('a1'));
        self::assertSame('A', XlsxReader::columnLetter(0));
        self::assertSame('Z', XlsxReader::columnLetter(25));
        self::assertSame('AA', XlsxReader::columnLetter(26));
    }

    public function testRefusesAFileThatIsNotAWorkbook(): void
    {
        file_put_contents($this->file, 'pas un classeur');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('essai.xlsx : ce n\'est pas un classeur XLSX');
        XlsxReader::read($this->file, 'essai.xlsx');
    }

    public function testRefusesAMissingFile(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('introuvable');
        XlsxReader::read($this->file . '.absent', 'absent.xlsx');
    }
}
