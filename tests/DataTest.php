<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\DataFiles;
use PolygonLab\PlateformesAgreees\Outils\Platform;
use PolygonLab\PlateformesAgreees\Outils\Sources;
use PolygonLab\PlateformesAgreees\Outils\Updater;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\Workspace;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

/**
 * Les fichiers publiés de data/ : conformes au schéma, cohérents entre eux.
 * Rejoué par l'intégration continue à chaque publication.
 */
final class DataTest extends TestCase
{
    private const DATA = __DIR__ . '/../data';

    private const SCHEMA = __DIR__ . '/../schema/plateformes.schema.json';

    public function testPublishedListMatchesTheSchema(): void
    {
        self::assertSchemaValid((string) file_get_contents(self::DATA . '/' . DataFiles::PLATFORMS_JSON));
    }

    public function testGeneratedListMatchesTheSchema(): void
    {
        $workspace = new Workspace();
        try {
            $inputs = $workspace->inputs(
                [XlsxBuilder::platform('Alpha Facturation'), ['@GP', 'via Esempio 20, Milan', 20124, 'Italie', null, 'gp@example.com', 46224]],
                [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
            );
            $result = (new Updater(Sources::load(), $workspace->data))->run($inputs, new \DateTimeImmutable('2026-11-14T08:00:00+01:00'));
        } finally {
            $workspace->remove();
        }

        self::assertSchemaValid($result['files'][DataFiles::PLATFORMS_JSON]);
        self::assertSchemaValid(Updater::emptyFiles(Sources::load())[DataFiles::PLATFORMS_JSON]);
    }

    public function testPublishedFilesAreConsistent(): void
    {
        $platforms = DataFiles::readPlatforms((string) file_get_contents(self::DATA . '/' . DataFiles::PLATFORMS_JSON));
        $list = DataFiles::decode((string) file_get_contents(self::DATA . '/' . DataFiles::PLATFORMS_JSON), DataFiles::PLATFORMS_JSON);
        $source = DataFiles::decode((string) file_get_contents(self::DATA . '/' . DataFiles::SOURCE_JSON), DataFiles::SOURCE_JSON);

        // Le CSV est le rendu exact du JSON.
        self::assertSame(DataFiles::platformsCsv(array_values($platforms)), file_get_contents(self::DATA . '/' . DataFiles::PLATFORMS_CSV));
        self::assertSame(\count($platforms), $list['nombre']);
        self::assertSame(array_keys($platforms), array_column(array_map(static fn(Platform $p): array => $p->toArray(), DataFiles::sorted(array_values($platforms))), 'cle'));

        // La source porte la même date et une entrée par fichier lu.
        self::assertSame($list['date_source'], $source['date_source']);
        self::assertIsArray($source['fichiers']);
        if ($platforms !== []) {
            self::assertSame(array_column(Sources::load()->files, 'cle'), array_column($source['fichiers'], 'cle'));
            foreach ($source['fichiers'] as $file) {
                self::assertIsArray($file);
                self::assertIsString($file['sha256']);
                self::assertIsString($file['url']);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $file['sha256']);
                self::assertStringStartsWith('https://', $file['url']);
            }
        }

        // L'historique commence par son en-tête.
        self::assertStringStartsWith(DataFiles::historyHeader(), (string) file_get_contents(self::DATA . '/' . DataFiles::HISTORY_CSV));
    }

    /**
     * Décision du 14/11/2026 : les courriels de contact de la DGFiP ne sont jamais publiés. Aucune valeur des
     * fichiers publiés ne contient « @ » suivi d'un domaine.
     */
    public function testPublishedFilesContainNoEmailAddress(): void
    {
        foreach (glob(self::DATA . '/*') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+/', (string) file_get_contents($file), basename($file) . ' contient une adresse e-mail');
        }
    }

    private static function assertSchemaValid(string $json): void
    {
        $data = json_decode($json);
        $schema = json_decode((string) file_get_contents(self::SCHEMA));
        self::assertIsObject($schema, 'schéma illisible');

        $validator = new Validator();
        $validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);

        self::assertTrue($validator->isValid(), (string) json_encode($validator->getErrors(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
