<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\OdsReader;
use PolygonLab\PlateformesAgreees\Outils\SourceError;
use PolygonLab\PlateformesAgreees\Outils\XlsxReader;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\OdsBuilder;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

/**
 * Lecture d'un classeur ODS : mêmes cas que XlsxReaderTest, plus ce que le format a de particulier (cellules et lignes
 * répétées, cellules couvertes par une fusion, types, texte en plusieurs morceaux).
 */
final class OdsReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/lecteur-essai-' . bin2hex(random_bytes(6)) . '.ods';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsTextNumbersDatesAndBooleans(): void
    {
        OdsBuilder::write($this->file, [
            ['Titre'],
            [],
            [
                'Raison sociale', null, 'inline:Chaîne en ligne', 111111118, 'num:1.11111118E8', 'num:12.5',
                'date:2025-12-11', 'date:2025-12-11T00:00:00', 'date:2025-12-11T08:30:00', 'bool:true', 'bool:false', 'empty:',
            ],
            ['Exemple & Fils « SAS »'],
        ], 'Plateformes');

        $book = OdsReader::read($this->file, 'essai.ods');

        self::assertSame('Plateformes', $book['sheet']);
        self::assertSame(1, $book['sheets']);
        self::assertSame([1, 3, 4], array_keys($book['rows']), 'ligne vide ignorée, fin de feuille répétée jamais dépliée');
        self::assertSame([
            0 => 'Raison sociale',
            2 => 'Chaîne en ligne',
            3 => '111111118',
            4 => '111111118',
            5 => '12.5',
            6 => '2025-12-11',
            7 => '2025-12-11',
            8 => '2025-12-11T08:30:00',
            9 => 'VRAI',
            10 => 'FAUX',
        ], $book['rows'][3], 'nombre : valeur enregistrée et non texte affiché ; date : AAAA-MM-JJ, heure gardée si elle n\'est pas minuit');
        self::assertSame([0 => 'Exemple & Fils « SAS »'], $book['rows'][4]);
        self::assertSame([], $book['merges']);
    }

    public function testReadsRepeatedCellsAndRowsWithoutUnfoldingEmptyOnes(): void
    {
        $emptyRow = '<table:table-cell table:number-columns-repeated="' . OdsBuilder::SHEET_COLUMNS . '"/>';
        $rows = '<table:table-row>' . OdsBuilder::cell('A1') . OdsBuilder::cell('Trois fois', ' table:number-columns-repeated="3"')
            . '<table:table-cell table:number-columns-repeated="2"/>' . OdsBuilder::cell('G1') . '<table:table-cell table:number-columns-repeated="16377"/></table:table-row>'
            . '<table:table-row table:number-rows-repeated="3">' . $emptyRow . '</table:table-row>'
            . '<table:table-row table:number-rows-repeated="2">' . OdsBuilder::cell('Deux lignes') . '</table:table-row>'
            . '<table:table-row table:number-rows-repeated="1048570">' . $emptyRow . '</table:table-row>'
            . '<table:table-row>' . OdsBuilder::cell(null) . OdsBuilder::cell('Dernière ligne') . '</table:table-row>';
        OdsBuilder::archive($this->file, OdsBuilder::document(OdsBuilder::table('Liste', $rows)));

        $book = OdsReader::read($this->file, 'essai.ods');

        self::assertSame([
            1 => [0 => 'A1', 1 => 'Trois fois', 2 => 'Trois fois', 3 => 'Trois fois', 6 => 'G1'],
            5 => [0 => 'Deux lignes'],
            6 => [0 => 'Deux lignes'],
            1048577 => [1 => 'Dernière ligne'],
        ], $book['rows'], 'cellule et ligne remplies recopiées, cellules et lignes vides seulement comptées');
    }

    public function testReadsRowsInsideHeaderRowsAndGroups(): void
    {
        $rows = '<table:table-header-rows><table:table-row>' . OdsBuilder::cell('En-tête') . '</table:table-row></table:table-header-rows>'
            . '<table:table-row-group><table:table-row>' . OdsBuilder::cell('Groupe') . '</table:table-row></table:table-row-group>'
            . '<table:table-row>' . OdsBuilder::cell('Suite') . '</table:table-row>';
        OdsBuilder::archive($this->file, OdsBuilder::document(OdsBuilder::table('Liste', $rows)));

        self::assertSame([1 => [0 => 'En-tête'], 2 => [0 => 'Groupe'], 3 => [0 => 'Suite']], OdsReader::read($this->file, 'essai.ods')['rows']);
    }

    public function testReadsMergedRanges(): void
    {
        OdsBuilder::file($this->file, [XlsxBuilder::platform('Alpha Facturation')]);

        $book = OdsReader::read($this->file, 'essai.ods');

        self::assertSame([[1, 0, 2, 0], [1, 1, 1, 3], [1, 4, 2, 4], [1, 5, 2, 5], [1, 6, 2, 6]], $book['merges'], 'plages A1:A2, B1:D1, E1:E2, F1:F2, G1:G2');
        self::assertSame("Adresse\nde l\u{2019}établis-\nsement principal", $book['rows'][1][1], 'un paragraphe par ligne de texte : sauts de ligne conservés');
        self::assertArrayNotHasKey(2, $book['rows'][1], 'C1 couverte par la fusion : la valeur est dans la première cellule de la plage');
        self::assertSame('2025-12-11', $book['rows'][3][6], 'cellule de date');
    }

    public function testACoveredCellNeverHasAValue(): void
    {
        $rows = '<table:table-row>' . OdsBuilder::cell('Fusion', ' table:number-columns-spanned="2" table:number-rows-spanned="1"')
            . '<table:covered-table-cell office:value-type="string"><text:p>Ancien texte caché</text:p></table:covered-table-cell>'
            . OdsBuilder::cell('C1') . '</table:table-row>';
        OdsBuilder::archive($this->file, OdsBuilder::document(OdsBuilder::table('Liste', $rows)));

        $book = OdsReader::read($this->file, 'essai.ods');

        self::assertSame([1 => [0 => 'Fusion', 2 => 'C1']], $book['rows']);
        self::assertSame([[1, 0, 1, 1]], $book['merges']);
    }

    public function testReadsParagraphsSpansSpacesLineBreaksAndLinks(): void
    {
        $rows = '<table:table-row>'
            . OdsBuilder::cell('xml:<text:p>Rue <text:span text:style-name="T1">de la</text:span><text:s/> Paix<text:s text:c="2"/>75002<text:tab/>Paris<text:line-break/>Cedex</text:p>'
                . '<text:p><text:a xlink:href="https://www.example.com/" xlink:type="simple">https://www.example.com</text:a></text:p>')
            . OdsBuilder::cell("xml:<text:p>\n      Mise   en\n      page  </text:p>")
            . OdsBuilder::cell('xml:<office:annotation><dc:date>2026-11-14T00:00:00</dc:date><text:p>Commentaire jamais lu</text:p></office:annotation>'
                . '<text:p>Valeur<text:note text:note-class="footnote"><text:note-body><text:p>note jamais lue</text:p></text:note-body></text:note></text:p>')
            . '<table:table-cell office:value-type="string" office:string-value="Valeur enregistrée"/>'
            . OdsBuilder::cell(' espace au début, deux  au milieu, un à la fin ')
            . '</table:table-row>';
        OdsBuilder::archive($this->file, OdsBuilder::document(OdsBuilder::table('Liste', $rows)));

        $row = OdsReader::read($this->file, 'essai.ods')['rows'][1];

        self::assertSame("Rue de la  Paix  75002\tParis\nCedex\nhttps://www.example.com", $row[0], 'morceaux, espaces, tabulation, saut de ligne, lien, second paragraphe');
        self::assertSame('Mise en page ', $row[1], 'espaces de mise en page du fichier : un seul, aucun au début');
        self::assertSame('Valeur', $row[2], 'commentaire et note hors de la valeur');
        self::assertSame('Valeur enregistrée', $row[3], 'texte sans paragraphe : valeur enregistrée');
        self::assertSame(' espace au début, deux  au milieu, un à la fin ', $row[4], 'espaces voulus conservés');
    }

    public function testReadsOnlyTheFirstSheet(): void
    {
        OdsBuilder::write($this->file, [['Première feuille']], 'Liste', [], ['Notes', 'Archives']);

        $book = OdsReader::read($this->file, 'essai.ods');

        self::assertSame('Liste', $book['sheet']);
        self::assertSame(3, $book['sheets']);
        self::assertSame([1 => [0 => 'Première feuille']], $book['rows']);
    }

    public function testGivesTheSameRowsAsTheXlsxReader(): void
    {
        $rows = [
            ['Liste à jour au 12/11/2026'],
            [],
            ['Nom commercial', "Adresse\nde l\u{2019}établis-\nsement principal", null, null, 'Site internet'],
            [null, 'Voie', 'Code postal', 'Commune (Pays le cas échéant)'],
            ['Alpha  Facturation ', ' 1 rue de l\'Exemple', 6200, 'Nice', 'https://www.example.com/alpha'],
            [],
            ['Bêta & Échanges « SAS »', 'inline:2 avenue de l\'Essai', 'num:7.5001E4', 'Paris', 'empty:'],
        ];
        $merges = ['A3:A4', 'B3:D3', 'E3:E4'];
        $xlsx = substr($this->file, 0, -4) . '.xlsx';
        try {
            XlsxBuilder::write($xlsx, $rows, 'Feuil1', $merges);
            OdsBuilder::write($this->file, $rows, 'Feuil1', $merges);

            self::assertSame(XlsxReader::read($xlsx, 'essai.xlsx'), OdsReader::read($this->file, 'essai.ods'));
        } finally {
            @unlink($xlsx);
        }
    }

    public function testRefusesAFileThatIsNotAWorkbook(): void
    {
        file_put_contents($this->file, 'pas un classeur');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('essai.ods : ce n\'est pas un classeur ODS');
        OdsReader::read($this->file, 'essai.ods');
    }

    public function testRefusesAnotherKindOfArchive(): void
    {
        // Un classeur XLSX renommé : archive lisible, mais sans le type d'un classeur ODS.
        XlsxBuilder::file($this->file, [XlsxBuilder::platform('Alpha Facturation')]);
        try {
            OdsReader::read($this->file, 'essai.ods');
            self::fail('classeur XLSX accepté comme classeur ODS');
        } catch (SourceError $error) {
            self::assertSame('essai.ods : ce n\'est pas un classeur ODS (type « absent »).', $error->getMessage());
        }

        // Un document texte OpenDocument : le type est cité.
        OdsBuilder::archive($this->file, OdsBuilder::document(''), 'application/vnd.oasis.opendocument.text');
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('essai.ods : ce n\'est pas un classeur ODS (type « application/vnd.oasis.opendocument.text »).');
        OdsReader::read($this->file, 'essai.ods');
    }

    public function testRefusesAMissingFile(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('introuvable');
        OdsReader::read($this->file . '.absent', 'absent.ods');
    }

    public function testRefusesAnUnreadableContent(): void
    {
        foreach ([
            'contenu vide' => '',
            'XML coupé' => substr(OdsBuilder::document(OdsBuilder::table('Liste', '<table:table-row>' . OdsBuilder::cell('A1') . '</table:table-row>')), 0, -40),
        ] as $label => $content) {
            OdsBuilder::archive($this->file, $content);
            try {
                OdsReader::read($this->file, 'essai.ods');
                self::fail($label . ' accepté');
            } catch (SourceError $error) {
                self::assertSame('essai.ods : XML invalide dans le classeur.', $error->getMessage(), $label);
            }
        }

        OdsBuilder::archive($this->file, OdsBuilder::document(''));
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('essai.ods : aucune feuille dans le classeur.');
        OdsReader::read($this->file, 'essai.ods');
    }

    public function testRefusesTooManyRowsOrColumnsAndCellsInError(): void
    {
        $cases = [
            'plus de 100000 lignes' => '<table:table-row table:number-rows-repeated="' . OdsBuilder::SHEET_ROWS . '">' . OdsBuilder::cell('Partout') . '</table:table-row>',
            'essai.ods, ligne 2 : plus de 16384 colonnes remplies' => '<table:table-row>' . OdsBuilder::cell('A1') . '</table:table-row>'
                . '<table:table-row>' . OdsBuilder::cell('Partout', ' table:number-columns-repeated="16385"') . '</table:table-row>',
            'essai.ods, ligne 3 : cellule en erreur.' => '<table:table-row table:number-rows-repeated="2">' . OdsBuilder::cell(null) . '</table:table-row>'
                . '<table:table-row><table:table-cell office:value-type="string" calcext:value-type="error"><text:p>#N/D</text:p></table:table-cell></table:table-row>',
        ];
        foreach ($cases as $message => $rows) {
            OdsBuilder::archive($this->file, OdsBuilder::document(OdsBuilder::table('Liste', $rows)));
            try {
                OdsReader::read($this->file, 'essai.ods');
                self::fail('accepté : ' . $message);
            } catch (SourceError $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }
}
