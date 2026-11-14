<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\DataFiles;
use PolygonLab\PlateformesAgreees\Outils\SourceError;
use PolygonLab\PlateformesAgreees\Outils\Sources;
use PolygonLab\PlateformesAgreees\Outils\Updater;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\Workspace;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

/**
 * Comparaison de deux lectures successives : entrées, sorties, changements de statut et de nom, historique.
 */
final class UpdaterTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testFirstReadWritesEveryFile(): void
    {
        $result = $this->run(
            [XlsxBuilder::platform('Alpha Facturation', 46002), ['@GP', 'via Esempio 20, Milan ', 20124, 'Italie', null, 'gp@example.com', 46224]],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
        );

        self::assertTrue($result['changed']);
        self::assertTrue($result['summary']['premiere_lecture']);
        self::assertSame('Première lecture de la liste du 12/11/2026 : 3 plateformes', $result['summary']['titre']);
        self::assertSame(0, $result['summary']['avant']);
        self::assertSame(3, $result['summary']['apres']);

        $json = DataFiles::decode($result['files'][DataFiles::PLATFORMS_JSON], DataFiles::PLATFORMS_JSON);
        self::assertSame('2026-11-12', $json['date_source']);
        self::assertSame(3, $json['nombre']);
        self::assertSame(['immatriculee' => 2, 'attente_tests_interoperabilite' => 1], $json['nombres']);
        self::assertSame(['nom:alpha-facturation', 'nom:gamma-factures', 'nom:gp'], array_column((array) $json['plateformes'], 'cle'));
        self::assertSame([
            'cle' => 'nom:gp',
            'nom_commercial' => '@GP',
            'statut' => 'immatriculee',
            'adresse' => ['voie' => 'via Esempio 20, Milan', 'code_postal' => '20124', 'commune_ou_pays' => 'Italie', 'pays' => 'Italie'],
            'site_internet' => null,
            'date_immatriculation' => '2026-07-21',
            'immatriculation_en_attente' => false,
        ], ((array) $json['plateformes'])[2]);

        $csv = $result['files'][DataFiles::PLATFORMS_CSV];
        self::assertStringStartsWith(DataFiles::BOM . "cle;nom_commercial;statut;adresse_voie;adresse_code_postal;adresse_commune_ou_pays;adresse_pays;site_internet;date_immatriculation;immatriculation_en_attente\n", $csv);
        self::assertStringContainsString("nom:gp;@GP;immatriculee;via Esempio 20, Milan;20124;Italie;Italie;;2026-07-21;non\n", $csv);
        self::assertStringContainsString("nom:gamma-factures;Gamma Factures;attente_tests_interoperabilite;1 rue de l'Exemple;75001;Paris;France;https://www.example.com/gamma-factures;;non\n", $csv);

        $history = $result['files'][DataFiles::HISTORY_CSV];
        self::assertStringStartsWith(DataFiles::BOM . "date;evenement;cle;statut;nom_commercial;detail\n", $history);
        self::assertSame(4, substr_count($history, "\n"));
        self::assertStringContainsString("2026-11-14;entree;nom:gamma-factures;attente_tests_interoperabilite;Gamma Factures;en attente des tests d'interopérabilité\n", $history);

        $source = DataFiles::decode($result['files'][DataFiles::SOURCE_JSON], DataFiles::SOURCE_JSON);
        self::assertSame('2026-11-14T08:00:00+01:00', $source['lu_le']);
        self::assertSame('2026-11-12', $source['date_source']);
        self::assertIsArray($source['fichiers']);
        self::assertCount(2, $source['fichiers']);
        $first = $source['fichiers'][0];
        self::assertIsArray($first);
        self::assertSame('immatriculee', $first['cle']);
        self::assertSame(hash_file('sha256', $this->workspace->path . '/liste_pa_attente_rapport_audit.xlsx'), $first['sha256']);
        self::assertIsArray($first['colonnes']);
        self::assertCount(7, $first['colonnes']);
        self::assertSame('2026-11-12T09:30:00+00:00', $first['derniere_modification']);
    }

    public function testNoEmailIsEverWrittenToTheDataFiles(): void
    {
        $result = $this->run(
            [XlsxBuilder::platform('Alpha Facturation'), XlsxBuilder::platform('@GP')],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
        );

        self::assertCount(4, $result['files']);
        foreach ($result['files'] as $name => $content) {
            self::assertDoesNotMatchRegularExpression('/@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+/', $content, $name . ' contient une adresse e-mail');
        }
        self::assertStringContainsString('@GP', $result['files'][DataFiles::PLATFORMS_JSON], '« @ » sans domaine (nom commercial) conservé');
    }

    public function testDetectsEntriesExitsStatusAndNameChanges(): void
    {
        $this->workspace->store($this->run(
            [XlsxBuilder::platform('Alpha Facturation'), XlsxBuilder::platform('Bêta Échanges')],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6), array_slice(XlsxBuilder::platform('Delta Flux'), 0, 6)],
        )['files']);
        $before = $this->workspace->read(DataFiles::HISTORY_CSV);

        // Gamma passe dans la liste des immatriculées, Bêta sort, Alpha change d'écriture, Epsilon entre.
        $result = $this->run(
            [XlsxBuilder::platform('ALPHA Facturation'), XlsxBuilder::platform('Gamma Factures', 46340)],
            [array_slice(XlsxBuilder::platform('Delta Flux'), 0, 6), array_slice(XlsxBuilder::platform('Epsilon Dématérialisation'), 0, 6)],
            new \DateTimeImmutable('2026-11-20T06:20:00+01:00'),
        );

        self::assertTrue($result['changed']);
        self::assertFalse($result['summary']['premiere_lecture']);
        self::assertSame('Liste du 12/11/2026 : 1 entrée, 1 sortie, 1 changement de statut, 1 changement de nom, 1 autre modification', $result['summary']['titre']);
        self::assertSame(4, $result['summary']['avant']);
        self::assertSame(4, $result['summary']['apres']);
        self::assertSame(1, $result['summary']['retraits']);
        self::assertSame(
            ['Entrées dans une liste', 'Sorties', 'Changements de statut', 'Changements de nom', 'Autres modifications', 'Listes'],
            array_column((array) $result['summary']['sections'], 'titre'),
        );

        $history = $result['files'][DataFiles::HISTORY_CSV];
        self::assertStringStartsWith($before, $history, 'l\'historique ne fait que s\'allonger');
        self::assertSame(
            "2026-11-20;changement_nom;nom:alpha-facturation;immatriculee;ALPHA Facturation;Alpha Facturation -> ALPHA Facturation\n"
            . "2026-11-20;entree;nom:epsilon-dematerialisation;attente_tests_interoperabilite;Epsilon Dématérialisation;en attente des tests d'interopérabilité\n"
            . "2026-11-20;changement_statut;nom:gamma-factures;immatriculee;Gamma Factures;attente_tests_interoperabilite -> immatriculee\n"
            . "2026-11-20;sortie;nom:beta-echanges;immatriculee;Bêta Échanges;retirée de la liste « immatriculée »\n",
            substr($history, \strlen($before)),
        );
        $others = ((array) $result['summary']['sections'])[4];
        self::assertIsArray($others);
        self::assertSame(['Gamma Factures (Paris) : date_immatriculation (vide) -> 2026-11-14'], $others['elements']);
    }

    public function testUnchangedListWritesNothing(): void
    {
        $registered = [XlsxBuilder::platform('Alpha Facturation')];
        $awaiting = [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)];
        $this->workspace->store($this->run($registered, $awaiting)['files']);

        $result = $this->run($registered, $awaiting, new \DateTimeImmutable('2026-11-15T06:20:00+01:00'));

        self::assertFalse($result['changed']);
        self::assertSame([], $result['files']);
        self::assertSame('Liste du 12/11/2026 : aucun changement', $result['summary']['titre']);
    }

    public function testRenderingIsDeterministic(): void
    {
        $registered = [XlsxBuilder::platform('Alpha Facturation'), XlsxBuilder::platform('Bêta Échanges')];
        $awaiting = [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)];

        $first = $this->run($registered, $awaiting)['files'];
        $second = $this->run(array_reverse($registered), $awaiting)['files'];

        self::assertSame($first[DataFiles::PLATFORMS_JSON], $second[DataFiles::PLATFORMS_JSON]);
        self::assertSame($first[DataFiles::PLATFORMS_CSV], $second[DataFiles::PLATFORMS_CSV]);
    }

    public function testRefusesChangedHeadersUnlessAccepted(): void
    {
        $awaiting = [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)];
        $this->workspace->store($this->run([XlsxBuilder::platform('Alpha Facturation')], $awaiting)['files']);

        // Même champ, intitulé écrit autrement (« d’ » typographique) : reconnu, mais différent de la lecture précédente.
        $rows = XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation')]);
        $rows[0][6] = 'Date de délivrance du numéro d’immatriculation';
        $first = XlsxBuilder::write($this->workspace->path . '/autre-a.xlsx', $rows, 'Feuil1', [XlsxBuilder::MERGE]);
        $second = XlsxBuilder::file($this->workspace->path . '/autre-b.xlsx', $awaiting, false);
        $inputs = $this->workspace->files($first, $second);
        $updater = new Updater(Sources::load(), $this->workspace->data);

        try {
            $updater->run($inputs, new \DateTimeImmutable('2026-11-15T06:20:00+01:00'));
            self::fail('intitulés modifiés acceptés');
        } catch (SourceError $error) {
            self::assertStringContainsString('colonnes modifiées depuis la dernière lecture', $error->getMessage());
            self::assertStringContainsString('Date de délivrance du numéro d’immatriculation', $error->getMessage());
        }

        $accepted = $updater->run($inputs, new \DateTimeImmutable('2026-11-15T06:20:00+01:00'), true);
        self::assertFalse($accepted['changed'], 'intitulés acceptés, données identiques');
    }

    public function testRefusesAPlatformListedTwice(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('figure dans les deux listes à la fois');
        $this->run([XlsxBuilder::platform('Alpha Facturation')], [array_slice(XlsxBuilder::platform('Alpha facturation'), 0, 6)]);
    }

    public function testRefusesAnEmptyList(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('liste_pa_attente_test_interop.xlsx : liste vide');
        $this->run([XlsxBuilder::platform('Alpha Facturation')], []);
    }

    public function testUsesTheServerDateInParisTime(): void
    {
        $inputs = $this->workspace->inputs(
            [XlsxBuilder::platform('Alpha Facturation')],
            [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)],
            '2026-11-10T23:30:00+00:00',
        );

        $result = (new Updater(Sources::load(), $this->workspace->data))->run($inputs, new \DateTimeImmutable('2026-11-14T08:00:00+01:00'));

        self::assertSame('2026-11-11', $result['summary']['date_source'], 'date du serveur ramenée à l\'heure de Paris');
    }

    public function testEmptyFilesAreValidAndEmpty(): void
    {
        $files = Updater::emptyFiles(Sources::load());

        $json = DataFiles::decode($files[DataFiles::PLATFORMS_JSON], DataFiles::PLATFORMS_JSON);
        self::assertSame([], $json['plateformes']);
        self::assertNull($json['date_source']);
        self::assertSame(DataFiles::historyHeader(), $files[DataFiles::HISTORY_CSV]);
    }

    /**
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $registered
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $awaiting
     *
     * @return array{changed: bool, files: array<string, string>, summary: array<string, mixed>}
     */
    private function run(array $registered, array $awaiting, ?\DateTimeImmutable $now = null): array
    {
        $inputs = $this->workspace->inputs($registered, $awaiting);

        return (new Updater(Sources::load(), $this->workspace->data))->run($inputs, $now ?? new \DateTimeImmutable('2026-11-14T08:00:00+01:00'));
    }
}
