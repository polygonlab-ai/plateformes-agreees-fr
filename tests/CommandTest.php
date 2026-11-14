<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\DataFiles;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\Workspace;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

/**
 * La commande tools/actualiser.php, lancée dans un processus séparé, avec des fichiers locaux (aucun appel réseau).
 */
final class CommandTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../tools/actualiser.php';

    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testUpdatesTheDataFromLocalFiles(): void
    {
        $inputs = $this->workspace->inputs(
            [XlsxBuilder::platform('Alpha Facturation')],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
        );
        $summary = $this->workspace->path . '/resume.json';

        [$code, $output] = $this->command([
            '--source', 'immatriculee=' . $inputs['immatriculee']['chemin'],
            '--source=attente_tests_interoperabilite=' . $inputs['attente_tests_interoperabilite']['chemin'],
            '--donnees', $this->workspace->data,
            '--resume', $summary,
        ]);

        self::assertSame(0, $code, $output);
        // Fichiers locaux sans date de modification connue : la liste est datée du jour de la lecture.
        self::assertStringContainsString('Première lecture de la liste du ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('d/m/Y') . ' : 2 plateformes', $output);
        self::assertStringContainsString('Fichiers de data/ réécrits.', $output);
        $data = DataFiles::decode($this->workspace->read(DataFiles::PLATFORMS_JSON), DataFiles::PLATFORMS_JSON);
        self::assertSame(2, $data['nombre']);
        $decoded = json_decode((string) file_get_contents($summary), true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['format']);
        self::assertTrue($decoded['change']);
        self::assertSame([], glob($this->workspace->data . '/.*.tmp') ?: [], 'aucun fichier temporaire laissé');

        // Deuxième passage identique : rien n'est réécrit.
        [$code, $output] = $this->command([
            '--manifeste', $this->manifest($inputs),
            '--donnees', $this->workspace->data,
        ]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Liste inchangée : aucun fichier modifié.', $output);
    }

    public function testRefusesABadSourceAndWritesNothing(): void
    {
        $before = $this->workspace->read(DataFiles::PLATFORMS_JSON);
        $inputs = $this->workspace->inputs(
            [['Alpha Facturation', null, null, null, null, 'alpha@example.com', 'demain']],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
        );

        [$code, $output] = $this->command(['--manifeste', $this->manifest($inputs), '--donnees', $this->workspace->data]);

        self::assertSame(1, $code);
        self::assertStringContainsString('date de délivrance « demain » illisible', $output);
        self::assertStringContainsString('Aucun fichier n\'a été écrit.', $output);
        self::assertSame($before, $this->workspace->read(DataFiles::PLATFORMS_JSON));
    }

    public function testRejectsUnknownArguments(): void
    {
        [$code, $output] = $this->command(['--inconnu']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Argument non reconnu : --inconnu.', $output);

        [$code, $output] = $this->command(['--source', 'autre=fichier.xlsx']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--source attend <liste>=<fichier.xlsx>', $output);
    }

    public function testShowsHelp(): void
    {
        [$code, $output] = $this->command(['--aide']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage : php tools/actualiser.php', $output);
    }

    /**
     * @param array<string, array{chemin: string, url: string, derniere_modification: string|null}> $inputs
     */
    private function manifest(array $inputs): string
    {
        $files = [];
        foreach ($inputs as $key => $input) {
            $files[] = ['cle' => $key] + $input;
        }
        $path = $this->workspace->path . '/manifeste.json';
        file_put_contents($path, json_encode(['fichiers' => $files]));

        return $path;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string} code de sortie, sorties standard et d'erreur réunies
     */
    private function command(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::SCRIPT], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
