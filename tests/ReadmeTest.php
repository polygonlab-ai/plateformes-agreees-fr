<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Le démarrage rapide du README est le script d'exemple, et il produit la sortie annoncée
 * (lancé ici sur une copie locale fabriquée, sans appel réseau).
 */
final class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const EXAMPLE = self::ROOT . '/examples/lire-la-liste.php';

    public function testQuickStartCodeIsTheExampleScript(): void
    {
        self::assertSame(rtrim((string) file_get_contents(self::EXAMPLE), "\n"), self::block('php'));
    }

    public function testQuickStartOutputIsTheExpectedOutput(): void
    {
        $copy = sys_get_temp_dir() . '/plateformes-exemple-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($copy, json_encode([
            'source' => 'DGFiP, impots.gouv.fr',
            'page' => 'https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees',
            'date_source' => '2026-11-12',
            'nombre' => 166,
            'nombres' => ['immatriculee' => 120, 'attente_tests_interoperabilite' => 46],
            'plateformes' => [
                ['cle' => 'nom:exemple-facturation', 'nom_commercial' => 'Exemple Facturation', 'statut' => 'immatriculee',
                    'adresse' => ['voie' => '1 rue de l\'Exemple', 'code_postal' => '75001', 'commune_ou_pays' => 'Paris', 'pays' => 'France'],
                    'site_internet' => 'https://www.example.com', 'date_immatriculation' => '2025-12-11', 'immatriculation_en_attente' => false],
                ['cle' => 'nom:exemple-echanges', 'nom_commercial' => 'Exemple Échanges', 'statut' => 'attente_tests_interoperabilite',
                    'adresse' => ['voie' => null, 'code_postal' => null, 'commune_ou_pays' => 'Belgique', 'pays' => 'Belgique'],
                    'site_internet' => null, 'date_immatriculation' => null, 'immatriculation_en_attente' => false],
            ],
        ]));
        try {
            $process = proc_open([PHP_BINARY, self::EXAMPLE, $copy], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        } finally {
            unlink($copy);
        }

        self::assertSame(self::block('text'), rtrim($output, "\n"));
    }

    public function testCurlExampleTargetsThePublishedFiles(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');

        foreach (['plateformes.json', 'plateformes.csv', 'historique.csv', 'source.json'] as $file) {
            self::assertStringContainsString('https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/' . $file, $readme);
            self::assertFileExists(self::ROOT . '/data/' . $file);
        }
    }

    /**
     * Premier bloc de code du langage donné dans la section « Démarrage rapide ».
     */
    private static function block(string $language): string
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        $start = strpos($readme, "## Démarrage rapide\n");
        self::assertNotFalse($start, 'section « Démarrage rapide » absente du README');

        $pattern = '/```' . preg_quote($language, '/') . '\n(.*?)\n```/s';
        self::assertSame(1, preg_match($pattern, substr($readme, $start), $matches), 'bloc ' . $language . ' absent');

        return $matches[1];
    }
}
