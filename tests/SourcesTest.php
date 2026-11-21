<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\Downloader;
use PolygonLab\PlateformesAgreees\Outils\Platform;
use PolygonLab\PlateformesAgreees\Outils\SourceError;
use PolygonLab\PlateformesAgreees\Outils\Sources;

/**
 * Déclaration des fichiers officiels (tools/sources.json) : fichier principal de chaque liste et formats de repli.
 */
final class SourcesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/sources-essai-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testEachListDeclaresItsOdsFallbackNextToTheXlsxFile(): void
    {
        $sources = Sources::load();

        foreach (Platform::STATUSES as $key) {
            $formats = $sources->formats($key);
            self::assertCount(2, $formats, $key . ' : le fichier XLSX, puis son format de repli ODS');
            self::assertStringEndsWith('.xlsx', $formats[0]['fichier']);
            self::assertSame($sources->file($key)['url'], $formats[0]['url'], 'le fichier principal est essayé le premier');
            self::assertSame(substr($formats[0]['fichier'], 0, -5) . '.ods', $formats[1]['fichier'], 'même nom, autre format');
            self::assertSame(substr($formats[0]['url'], 0, -5) . '.ods', $formats[1]['url'], 'même dossier officiel');
        }
    }

    public function testFindsTheDeclaredFormatOfAFileActuallyRead(): void
    {
        $sources = Sources::load();
        [$main, $fallback] = $sources->formats(Platform::STATUS_AWAITING_TESTS);

        self::assertSame($main, $sources->format(Platform::STATUS_AWAITING_TESTS, '/essai/copie.xlsx'));
        self::assertSame($fallback, $sources->format(Platform::STATUS_AWAITING_TESTS, '/essai/copie.ODS'), 'extension sans tenir compte des majuscules');
        self::assertSame($fallback, $sources->format(Platform::STATUS_AWAITING_TESTS, '/essai/copie', $fallback['url']), 'adresse connue : elle décide');
        self::assertSame($main, $sources->format(Platform::STATUS_AWAITING_TESTS, '/essai/copie.pdf'), 'format non déclaré : fichier principal');
        self::assertSame('xlsx', Sources::extension('Liste.XLSX'));
        self::assertSame('ods', Sources::extension('/dossier.v2/liste.ods'));
        self::assertNull(Sources::extension('liste.pdf'), 'le PDF n\'est pas un format lu');
        self::assertNull(Sources::extension('liste'));
    }

    public function testAListWithoutFallbackHasOnlyItsMainFile(): void
    {
        $sources = Sources::load($this->declaration(['replis' => []]));

        self::assertCount(1, $sources->formats(Platform::STATUS_REGISTERED));
        self::assertCount(2, $sources->formats(Platform::STATUS_AWAITING_TESTS));
        self::assertSame($sources->formats(Platform::STATUS_REGISTERED)[0], $sources->format(Platform::STATUS_REGISTERED, '/essai/copie.ods'));
    }

    public function testRefusesAnInvalidFallbackDeclaration(): void
    {
        $url = 'https://www.example.com/listes/';
        $cases = [
            'replis qui n\'est pas une liste' => ['replis' => ['fichier' => 'liste.ods', 'url' => $url . 'liste.ods']],
            'repli sans adresse' => ['replis' => [['fichier' => 'liste.ods']]],
            'adresse qui n\'est pas en https' => ['replis' => [['fichier' => 'liste.ods', 'url' => 'http://www.example.com/liste.ods']]],
            'format que l\'outil ne lit pas (PDF)' => ['replis' => [['fichier' => 'liste.pdf', 'url' => $url . 'liste.pdf']]],
            'nom de fichier avec un dossier' => ['replis' => [['fichier' => 'dossier/liste.ods', 'url' => $url . 'liste.ods']]],
            'adresse du fichier principal répétée' => ['url' => $url . 'liste.xlsx', 'replis' => [['fichier' => 'liste.ods', 'url' => $url . 'liste.xlsx']]],
            'plus de formats de repli que permis' => ['replis' => array_map(static fn(int $rank): array => ['fichier' => 'liste-' . $rank . '.ods', 'url' => $url . $rank . '.ods'], range(1, Sources::MAX_FALLBACKS + 1))],
        ];
        foreach ($cases as $label => $change) {
            try {
                Sources::load($this->declaration($change));
                self::fail('déclaration acceptée : ' . $label);
            } catch (SourceError $error) {
                self::assertStringContainsString('« replis » de la liste « immatriculee »', $error->getMessage(), $label);
            }
        }

        // Le même nom de fichier pour deux listes : les fichiers téléchargés s'écraseraient.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('un même nom de fichier ne peut être déclaré qu\'une fois');
        Sources::load($this->declaration(['replis' => [['fichier' => 'liste_pa_attente_test_interop.ods', 'url' => $url . 'autre.ods']]]));
    }

    public function testRefusesAMainFileThatIsNeitherXlsxNorOds(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('doit être un classeur XLSX ou ODS');
        Sources::load($this->declaration(['fichier' => 'liste_pa_attente_rapport_audit.pdf']));
    }

    public function testOnlyAMissingFileLeadsToTheNextFormat(): void
    {
        self::assertTrue(Downloader::missing(404));
        self::assertTrue(Downloader::missing(410));
        foreach ([0, 200, 403, 429, 500, 503] as $status) {
            self::assertFalse(Downloader::missing($status), 'HTTP ' . $status . ' : pas de repli');
        }
    }

    /**
     * Copie de tools/sources.json où des champs de la première liste (immatriculee) sont remplacés.
     *
     * @param array<string, mixed> $change
     */
    private function declaration(array $change): string
    {
        $data = json_decode((string) file_get_contents(Sources::FILE), true);
        self::assertIsArray($data);
        self::assertIsArray($data['fichiers']);
        self::assertIsArray($data['fichiers'][0]);
        $data['fichiers'][0] = array_merge($data['fichiers'][0], $change);
        file_put_contents($this->file, json_encode($data));

        return $this->file;
    }
}
