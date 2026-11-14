<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PlateformesAgreees\Outils\Columns;
use PolygonLab\PlateformesAgreees\Outils\ListReader;
use PolygonLab\PlateformesAgreees\Outils\Platform;
use PolygonLab\PlateformesAgreees\Outils\SourceError;
use PolygonLab\PlateformesAgreees\Outils\Sources;
use PolygonLab\PlateformesAgreees\Tests\Fixtures\XlsxBuilder;

/**
 * Lecture d'un classeur de même structure que ceux de la DGFiP. Noms fictifs, courriels en example.com.
 */
final class ListReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/liste-essai-' . bin2hex(random_bytes(6)) . '.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsTheTwoRowHeaderAndTheData(): void
    {
        XlsxBuilder::file($this->file, [
            XlsxBuilder::platform('Alpha Facturation', 46002),
            ['@GP', 'via Esempio 20, Milan ', 20124, 'Italie', 'https://www.example.com/gp ', 'gp@example.com', 46224],
            ['Bêta Échanges', '2 avenue de l\'Essai', '1930', 'Belgique', null, 'beta@example.com', '15/01/2026'],
        ]);

        $result = ListReader::read($this->file, 'liste.xlsx', Platform::STATUS_REGISTERED, $this->expected(Platform::STATUS_REGISTERED));

        self::assertSame('Feuil1', $result['sheet']);
        self::assertSame([
            'Nom commercial',
            'Adresse de l’établissement principal > Voie',
            'Adresse de l’établissement principal > Code postal',
            'Adresse de l’établissement principal > Commune (Pays le cas échéant)',
            'Site internet',
            'Courriel de contact',
            'Date de délivrance du numéro d\'immatriculation',
        ], $result['columns']);
        self::assertCount(3, $result['platforms']);
        [$alpha, $gp, $beta] = $result['platforms'];

        self::assertSame('nom:alpha-facturation', $alpha->key);
        self::assertSame('2025-12-11', $alpha->fields['date_immatriculation'], 'nombre de série Excel converti');
        self::assertSame('nom:gp', $gp->key, 'nom commençant par « @ »');
        self::assertSame('@GP', $gp->fields['nom_commercial']);
        self::assertSame('via Esempio 20, Milan', $gp->fields['adresse_voie'], 'espaces de fin retirés');
        self::assertSame('20124', $gp->fields['adresse_code_postal']);
        self::assertSame('Italie', $gp->fields['adresse_commune_ou_pays'], 'pays d\'une plateforme étrangère');
        self::assertSame('Italie', $gp->fields['adresse_pays']);
        self::assertSame('France', $alpha->fields['adresse_pays']);
        self::assertSame('https://www.example.com/gp', $gp->fields['site_internet']);
        self::assertSame('2026-07-21', $gp->fields['date_immatriculation']);
        self::assertSame('2026-01-15', $beta->fields['date_immatriculation'], 'date écrite en texte');
        self::assertNull($beta->fields['site_internet']);
        foreach ($result['platforms'] as $platform) {
            self::assertArrayNotHasKey('courriel', $platform->fields, 'le courriel est lu mais jamais gardé');
            self::assertStringNotContainsString('@example.com', (string) json_encode($platform->toArray()));
        }
    }

    /**
     * Les valeurs réelles relevées dans les fichiers de la DGFiP le 14/11/2026 (colonne C / colonne D).
     */
    public function testNormalizesPostalCodesFromTheRealFiles(): void
    {
        $cases = [
            // Commune française, code à 4 chiffres : zéro de tête rétabli.
            [6200, 'Nice', '06200', 'France'],
            [2100, 'Saint Quentin', '02100', 'France'],
            // Commune française, code texte avec espace.
            [' 34000', 'Montpellier', '34000', 'France'],
            // Pays étranger, code à 4 chiffres : inchangé.
            [3010, ' Belgique', '3010', 'Belgique'],
            [9400, 'Danemark', '9400', 'Danemark'],
            [1000, 'Slovénie', '1000', 'Slovénie'],
            [8019, 'Espagne', '8019', 'Espagne'],
            [1082, 'Chypre', '1082', 'Chypre'],
            [1896, 'Luxembourg', '1896', 'Luxembourg'],
            [2132, 'Pays-Bas', '2132', 'Pays-Bas'],
            // Pays entre parenthèses après une ville.
            [2600, ' Espoo (Finlande)', '2600', 'Finlande'],
            // Codes étrangers non numériques.
            ['111 51', 'Suède', '111 51', 'Suède'],
            ['D04TR29', 'Irlande', 'D04TR29', 'Irlande'],
            ['1211 BT', 'Pays-Bas', '1211 BT', 'Pays-Bas'],
            [1050, 'Portugal', '1050', 'Portugal'],
            // Pas de code postal.
            [null, 'Pologne', null, 'Pologne'],
            [null, 'Australie', null, 'Australie'],
        ];
        $platforms = [];
        foreach ($cases as $index => [$code, $place]) {
            $platforms[] = ['Plateforme ' . $index, 'Voie', $code, $place, null, null, 46002];
        }
        XlsxBuilder::file($this->file, $platforms);

        $result = ListReader::read($this->file, 'liste.xlsx', Platform::STATUS_REGISTERED, $this->expected(Platform::STATUS_REGISTERED));

        self::assertCount(\count($cases), $result['platforms']);
        foreach ($result['platforms'] as $index => $platform) {
            [$code, $place, $expectedCode, $expectedCountry] = $cases[$index];
            $label = var_export($code, true) . ' / ' . $place;
            self::assertSame($expectedCode, $platform->fields['adresse_code_postal'], $label . ' : code postal');
            self::assertSame($expectedCountry, $platform->fields['adresse_pays'], $label . ' : pays');
            self::assertSame(trim($place), $platform->fields['adresse_commune_ou_pays'], $label . ' : commune ou pays, espaces retirés');
        }
    }

    public function testRecognizesCountriesWithoutAccents(): void
    {
        self::assertSame(['111 51', 'Suède', null], ListReader::postalCode('111 51', 'SUEDE'));
        self::assertSame(['2132', 'Pays-Bas', null], ListReader::postalCode('2132', 'pays bas'));
        self::assertSame(['97400', 'France', null], ListReader::postalCode('97400', 'Saint-Denis (La Réunion)'), 'département d\'outre-mer : adresse française');
        self::assertSame(['75008', 'France', null], ListReader::postalCode('75008', 'Paris (France)'));
    }

    public function testStopsOnAnInvalidFrenchPostalCode(): void
    {
        try {
            $this->read(XlsxBuilder::list([
                ['Alpha Facturation', 'Voie', 123, 'Lyon', null, null, 46002],
                ['Bêta Échanges', 'Voie', null, 'Lille', null, null, 46002],
            ]), Platform::STATUS_REGISTERED);
            self::fail('code postal français invalide accepté');
        } catch (SourceError $error) {
            self::assertStringContainsString('ligne 3 : code postal « 123 » invalide pour une adresse française (commune « Lyon ») : 4 ou 5 chiffres attendus', $error->getMessage());
            self::assertStringContainsString('ligne 4 : code postal absent pour une adresse française (commune « Lille »)', $error->getMessage());
        }
    }

    /**
     * Tous les cas atypiques relevés dans les vrais fichiers le 14/11/2026, dans un seul classeur : fusions A1:A2,
     * B1:D1, E1:E2, F1:F2, G1:G2 ; nom « @GP » ; « En attente » à la place de la date ; dates entre 46002 et 46329 ;
     * cellule vide stylée en colonne AMI ; ligne de cellules présentes mais vides ; site en http://.
     */
    public function testReadsEveryAtypicalCaseOfTheRealFirstFile(): void
    {
        $rows = XlsxBuilder::list([
            ['@GP', 'via Esempio 20, Milan', 20124, 'Italie', 'https://www.example.com/gp', 'gp@example.com', 46002],
            ['Alpha Facturation', '1 rue de l\'Exemple', 6200, 'Nice', 'http://www.example.com/alpha', 'alpha@example.com', 'En attente'],
            ['Bêta Échanges', '2 rue de l\'Essai', 34000, 'Montpellier', 'https://www.example.com/beta', 'beta@example.com', '  EN ATTENTE '],
            ['Gamma Factures', '3 rue de l\'Essai', 75001, 'Paris', 'https://www.example.com/gamma', 'gamma@example.com', 46329],
        ]);
        $rows[4] = array_pad($rows[4], 1023, null);
        $rows[4][1022] = 'empty:';
        $rows[] = ['empty:', 'empty:', 'empty:', 'empty:', 'empty:', 'empty:', 'inline: '];
        XlsxBuilder::write($this->file, $rows, 'Feuil1', XlsxBuilder::merges());

        $result = ListReader::read($this->file, 'liste.xlsx', Platform::STATUS_REGISTERED, $this->expected(Platform::STATUS_REGISTERED));

        self::assertCount(4, $result['platforms'], 'cellule vide stylée en AMI et ligne de cellules vides ignorées');
        [$gp, $alpha, $beta, $gamma] = $result['platforms'];
        self::assertSame('@GP', $gp->fields['nom_commercial'], '« @GP » n\'est pas une adresse e-mail');
        self::assertSame('2025-12-11', $gp->fields['date_immatriculation']);
        self::assertFalse($gp->toArray()['immatriculation_en_attente']);
        self::assertNull($alpha->fields['date_immatriculation'], '« En attente » : date inconnue');
        self::assertTrue($alpha->toArray()['immatriculation_en_attente']);
        self::assertSame('oui', $alpha->toCsv()[9]);
        self::assertSame('http://www.example.com/alpha', $alpha->fields['site_internet'], 'site en http:// accepté');
        self::assertTrue($beta->toArray()['immatriculation_en_attente'], 'casse et espaces ignorés');
        self::assertSame('2026-11-03', $gamma->fields['date_immatriculation']);
        self::assertSame('non', $gamma->toCsv()[9]);
        self::assertSame('Nom commercial', $result['columns'][0], 'fusion verticale A1:A2 : intitulé de la ligne 1');
        self::assertSame('Date de délivrance du numéro d\'immatriculation', $result['columns'][6], 'fusion verticale G1:G2');
    }

    public function testReadsEveryAtypicalCaseOfTheRealSecondFile(): void
    {
        $rows = XlsxBuilder::list([
            ['Delta Flux', '4 rue de l\'Essai', 69001, 'Lyon', 'https://www.example.com/delta', 'delta@example.com'],
            ['Epsilon Dématérialisation', 'Rue de l\'Exemple 5', 1000, 'Belgique', null, 'epsilon@example.com'],
        ], false);
        $rows[] = ['empty:', 'empty:', 'empty:', 'empty:', 'empty:', 'empty:'];
        XlsxBuilder::write($this->file, $rows, 'Feuil1', XlsxBuilder::merges(false));

        $result = ListReader::read($this->file, 'liste.xlsx', Platform::STATUS_AWAITING_TESTS, $this->expected(Platform::STATUS_AWAITING_TESTS));

        self::assertCount(2, $result['platforms'], 'ligne de cellules présentes mais vides ignorée');
        self::assertNull($result['platforms'][1]->fields['site_internet'], 'sans site internet : null, pas d\'arrêt');
        foreach ($result['platforms'] as $platform) {
            self::assertFalse($platform->toArray()['immatriculation_en_attente'], 'toujours false sans colonne G');
            self::assertNull($platform->fields['date_immatriculation']);
        }
    }

    public function testStopsOnAnyOtherTextInTheDateColumn(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ligne 3 : date de délivrance « En attente de la DGFiP » illisible');
        $this->read(XlsxBuilder::list([['Alpha Facturation', 'Voie', 75001, 'Paris', null, null, 'En attente de la DGFiP']]), Platform::STATUS_REGISTERED);
    }

    public function testStopsOnAValueInAFarColumn(): void
    {
        $rows = XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation')]);
        $rows[2] = array_pad($rows[2], 1023, null);
        $rows[2][1022] = 'x';

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ligne 3 : valeur en colonne AMI, sous un en-tête vide');
        $this->read($rows, Platform::STATUS_REGISTERED);
    }

    public function testReadsTheSecondListWithoutDateColumn(): void
    {
        XlsxBuilder::file($this->file, [array_slice(XlsxBuilder::platform('Gamma Factures'), 0, 6)], false);

        $result = ListReader::read($this->file, 'liste.xlsx', Platform::STATUS_AWAITING_TESTS, $this->expected(Platform::STATUS_AWAITING_TESTS));

        self::assertCount(6, $result['columns']);
        self::assertNull($result['platforms'][0]->fields['date_immatriculation']);
        self::assertSame(Platform::STATUS_AWAITING_TESTS, $result['platforms'][0]->status);
    }

    public function testNormalizesHeaders(): void
    {
        self::assertSame('adresse de l etablissement principal', Columns::normalize("Adresse\nde l\u{2019}établis-\nsement principal"));
        self::assertSame('adresse_commune_ou_pays', Columns::field("Adresse  de l'établissement\r\nprincipal", 'Commune (Pays le cas échéant)'));
        self::assertSame('date_immatriculation', Columns::field("Date de délivrance du numéro\nd’immatriculation"));
        self::assertNull(Columns::field('Raison sociale'));
    }

    public function testStopsOnAnUnknownHeader(): void
    {
        $rows = XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation')]);
        $rows[0][7] = 'SIREN';

        try {
            $this->read($rows, Platform::STATUS_REGISTERED);
            self::fail('intitulé inconnu accepté');
        } catch (SourceError $error) {
            self::assertStringContainsString('colonne H : intitulé « SIREN » inconnu', $error->getMessage());
            self::assertStringContainsString('Intitulés lus : « Nom commercial »', $error->getMessage());
            self::assertStringContainsString('tools/src/Columns.php', $error->getMessage());
        }
    }

    public function testStopsWhenTheDateColumnIsMissingFromTheFirstFile(): void
    {
        try {
            $this->read(XlsxBuilder::list([array_slice(XlsxBuilder::platform('Alpha Facturation'), 0, 6)], false), Platform::STATUS_REGISTERED);
            self::fail('colonne G manquante acceptée');
        } catch (SourceError $error) {
            self::assertStringContainsString('colonnes différentes de celles attendues pour ce fichier', $error->getMessage());
            self::assertStringContainsString('Attendues : nom_commercial, adresse_voie, adresse_code_postal, adresse_commune_ou_pays, site_internet, courriel, date_immatriculation', $error->getMessage());
        }
    }

    public function testStopsWhenTheSecondFileGainsADateColumn(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('colonnes différentes de celles attendues');
        $this->read(XlsxBuilder::list([XlsxBuilder::platform('Gamma Factures')]), Platform::STATUS_AWAITING_TESTS);
    }

    public function testStopsWithoutAHeader(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ligne d\'en-tête introuvable');
        $this->read([['Titre'], ['Raison sociale', 'SIREN'], ['Exemple', '111111118']], Platform::STATUS_REGISTERED);
    }

    public function testStopsOnAnUnreadableDate(): void
    {
        try {
            $this->read(XlsxBuilder::list([
                XlsxBuilder::platform('Alpha Facturation', 46002),
                ['Bêta Échanges', null, null, null, null, null, 'bientôt'],
                ['Gamma Factures', null, null, null, null, null, '31/02/2026'],
                ['Delta Flux', null, null, null, null, null, 12],
            ]), Platform::STATUS_REGISTERED);
            self::fail('date illisible acceptée');
        } catch (SourceError $error) {
            self::assertStringContainsString('ligne 4 : date de délivrance « bientôt » illisible', $error->getMessage());
            self::assertStringContainsString('ligne 5 : date de délivrance « 31/02/2026 » illisible', $error->getMessage());
            self::assertStringContainsString('ligne 6 : date de délivrance « 12 » illisible', $error->getMessage());
        }
    }

    public function testConvertsExcelDates(): void
    {
        self::assertSame('2025-12-11', ListReader::date('46002'));
        self::assertSame('2026-01-29', ListReader::date('46051.5'), 'heure ignorée');
        self::assertSame('2026-11-12', ListReader::date('2026-11-12T00:00:00'));
        self::assertNull(ListReader::date('1/13/2026'));
        self::assertNull(ListReader::date('-3'));
    }

    public function testStopsOnADuplicateKeyAndCitesBothLines(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('lignes 3 et 4 : même clé nom:alpha-facturation');
        $this->read(XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation'), XlsxBuilder::platform('ALPHA facturation !')]), Platform::STATUS_REGISTERED);
    }

    public function testRefusesAnEmailOutsideTheContactColumn(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ligne 3 : adresse e-mail dans le champ « site_internet » : jamais publiée');
        $this->read(XlsxBuilder::list([['Alpha Facturation', null, null, null, 'contact@example.com', null, 46002]]), Platform::STATUS_REGISTERED);
    }

    public function testIgnoresEmptyRowsAndRequiresAName(): void
    {
        $rows = XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation')]);
        $rows[] = [];
        $rows[] = ['inline:   '];
        $rows[] = XlsxBuilder::platform('Gamma Factures');
        $result = $this->read($rows, Platform::STATUS_REGISTERED);
        self::assertCount(2, $result['platforms'], 'lignes vides ignorées');

        $rows[] = [null, 'Rue sans nom'];
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('ligne 7 : nom commercial absent');
        $this->read($rows, Platform::STATUS_REGISTERED);
    }

    public function testStopsOnAnEmptyList(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('liste vide');
        $this->read(XlsxBuilder::list([]), Platform::STATUS_REGISTERED);
    }

    public function testStopsOnAValueUnderAnEmptyHeader(): void
    {
        $rows = XlsxBuilder::list([XlsxBuilder::platform('Alpha Facturation')]);
        $rows[2][9] = 'x';

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('colonne J, sous un en-tête vide');
        $this->read($rows, Platform::STATUS_REGISTERED);
    }

    /**
     * @return list<string>
     */
    private function expected(string $status): array
    {
        return Sources::load()->file($status)['colonnes'];
    }

    /**
     * @param list<list<string|int|null>> $rows
     *
     * @return array{sheet: string, sheets: int, columns: list<string>, platforms: list<Platform>, stated_date: string|null}
     */
    private function read(array $rows, string $status): array
    {
        XlsxBuilder::write($this->file, $rows, 'Feuil1', XlsxBuilder::merges($status === Platform::STATUS_REGISTERED));

        return ListReader::read($this->file, 'liste.xlsx', $status, $this->expected($status));
    }
}
