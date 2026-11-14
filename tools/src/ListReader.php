<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Lit une liste de plateformes de la DGFiP (un fichier XLSX) et la contrôle.
 *
 * Structure des fichiers (constatée le 14/11/2026) : une feuille, un en-tête sur deux lignes.
 *
 *   ligne 1  Nom commercial | Adresse de l'établissement principal (fusionnée sur 3 colonnes) | Site internet | ...
 *   ligne 2                 | Voie | Code postal | Commune (Pays le cas échéant)               |               | ...
 *   ligne 3  données
 *
 * Déroulé :
 *   1. première feuille du classeur ;
 *   2. ligne d'en-tête : la première des 30 premières lignes qui contient « Nom commercial » ;
 *   3. intitulé complet de chaque colonne : celui de la ligne 1 (ou de la plage fusionnée qui la couvre, ou à défaut
 *      celui de la colonne remplie la plus proche à gauche), suivi de la sous-colonne de la ligne 2 ; la ligne 2 n'est
 *      lue comme en-tête que si toutes ses cellules donnent des intitulés reconnus ;
 *   4. chaque intitulé est reconnu par Columns, sinon arrêt (intitulé inconnu) ; la suite des champs doit être
 *      exactement celle attendue pour ce fichier (tools/sources.json), sinon arrêt (colonne manquante ou en trop) ;
 *   5. lignes de données : les lignes entièrement vides sont ignorées, les espaces de début et de fin retirés ;
 *   6. contrôles : nom commercial présent, date lisible (ou « En attente » : date null et immatriculation_en_attente), code postal valide pour une adresse française, aucune adresse
 *      e-mail hors de la colonne des courriels (qui n'est jamais publiée), aucune clé en double.
 *
 * Code postal et pays (décision du 14/11/2026). Excel a enregistré la plupart des codes postaux comme des nombres et
 * perdu le zéro de tête des codes français (« 6200 » pour Nice). Règle déterministe :
 *   - l'adresse est étrangère si la colonne « Commune (Pays le cas échéant) », ou le texte entre parenthèses à sa fin,
 *     est un pays de la liste fermée Countries (ISO 3166-1 en français) ; sinon elle est française ;
 *   - adresse française : code de 4 ou 5 chiffres, 4 chiffres reçoivent un « 0 » en tête ; tout autre format (vide
 *     compris) arrête la lecture, ligne citée ; pays « France » ;
 *   - adresse étrangère : code repris tel quel (texte), vide donne null ; pays reconnu.
 *
 * Toute anomalie est collectée, puis la lecture s'arrête en les citant toutes : rien n'est écrit tant que le fichier
 * n'est pas entièrement sain.
 */
final class ListReader
{
    /** Nombre de lignes parcourues pour trouver l'en-tête. */
    public const HEADER_SEARCH_ROWS = 30;

    /** Une adresse e-mail, ou au moins « @ » suivi d'un domaine : jamais publiée. */
    public const EMAIL_PATTERN = '/@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/';

    /** Dates admises en nombre de série Excel : du 01/01/2000 au 31/12/2100. */
    private const SERIAL_MIN = 36526;
    private const SERIAL_MAX = 73415;

    /**
     * @param list<string> $expected champs attendus, dans l'ordre des colonnes (tools/sources.json)
     *
     * @return array{
     *     sheet: string,
     *     sheets: int,
     *     columns: list<string>,
     *     platforms: list<Platform>,
     *     stated_date: string|null
     * }
     *
     * @throws SourceError
     */
    public static function read(string $path, string $label, string $status, array $expected): array
    {
        $book = XlsxReader::read($path, $label);
        $rows = $book['rows'];

        [$headerRow, $firstDataRow, $mapping, $columns] = self::header($rows, $book['merges'], $label, $expected);
        $statedDate = self::statedDate($rows, $headerRow);

        $platforms = [];
        $anomalies = [];
        $seen = [];
        foreach ($rows as $number => $cells) {
            if ($number < $firstDataRow) {
                continue;
            }
            $fields = [];
            foreach ($cells as $index => $value) {
                $clean = Text::clean($value);
                if ($clean === null) {
                    continue;
                }
                if (!isset($mapping[$index])) {
                    throw new SourceError(sprintf('%s, ligne %d : valeur en colonne %s, sous un en-tête vide.', $label, $number, XlsxReader::columnLetter($index)));
                }
                $fields[$mapping[$index]] = $clean;
            }
            if ($fields === []) {
                continue;
            }

            if (isset($fields['date_immatriculation']) && self::isPending($fields['date_immatriculation'])) {
                // « En attente » : date de délivrance pas encore connue (seul texte accepté à la place d'une date).
                $fields['date_immatriculation'] = null;
                $fields['immatriculation_en_attente'] = Columns::YES;
            } elseif (isset($fields['date_immatriculation'])) {
                $date = self::date($fields['date_immatriculation']);
                if ($date === null) {
                    $anomalies[] = sprintf('%s, ligne %d : date de délivrance « %s » illisible.', $label, $number, $fields['date_immatriculation']);
                }
                $fields['date_immatriculation'] = $date;
            }
            [$fields['adresse_code_postal'], $fields['adresse_pays'], $postalError] = self::postalCode(
                $fields['adresse_code_postal'] ?? null,
                $fields['adresse_commune_ou_pays'] ?? null,
            );
            if ($postalError !== null) {
                $anomalies[] = sprintf('%s, ligne %d : %s', $label, $number, $postalError);
            }
            foreach ($fields as $field => $value) {
                if ($field !== Columns::NEVER_PUBLISHED && $value !== null && preg_match(self::EMAIL_PATTERN, $value) === 1) {
                    $anomalies[] = sprintf('%s, ligne %d : adresse e-mail dans le champ « %s » : jamais publiée.', $label, $number, $field);
                }
            }
            $name = $fields['nom_commercial'] ?? null;
            if ($name === null) {
                $anomalies[] = sprintf('%s, ligne %d : nom commercial absent.', $label, $number);
                continue;
            }
            $platform = Platform::create($status, $fields);
            if ($platform->key === '') {
                $anomalies[] = sprintf('%s, ligne %d : nom commercial « %s » sans lettre ni chiffre (clé impossible).', $label, $number, $name);
                continue;
            }
            if (isset($seen[$platform->key])) {
                $anomalies[] = sprintf('%s, lignes %d et %d : même clé %s (« %s »).', $label, $seen[$platform->key], $number, $platform->key, $name);
                continue;
            }
            $seen[$platform->key] = $number;
            $platforms[] = $platform;
        }

        if ($anomalies !== []) {
            throw new SourceError(implode("\n", $anomalies));
        }
        if ($platforms === []) {
            throw new SourceError(sprintf('%s : liste vide (aucune plateforme sous l\'en-tête).', $label));
        }

        return [
            'sheet' => $book['sheet'],
            'sheets' => $book['sheets'],
            'columns' => $columns,
            'platforms' => $platforms,
            'stated_date' => $statedDate,
        ];
    }

    /**
     * Vrai pour le texte « En attente » écrit à la place d'une date de délivrance, sans tenir compte des majuscules,
     * des accents ni des espaces.
     */
    public static function isPending(string $value): bool
    {
        return Columns::normalize($value) === Columns::normalize(Columns::DATE_PENDING);
    }

    /**
     * Code postal publié, pays et anomalie éventuelle, d'après les colonnes « Code postal » et « Commune (Pays le cas
     * échéant) » (valeurs déjà nettoyées de leurs espaces de début et de fin).
     *
     * @return array{0: string|null, 1: string, 2: string|null} code postal, pays, anomalie (null si aucune)
     */
    public static function postalCode(?string $code, ?string $place): array
    {
        $code = Text::clean($code);
        $country = Countries::find($place);
        if ($country !== null) {
            return [$code, $country, null];
        }
        if ($code !== null && preg_match('/\A[0-9]{4,5}\z/', $code) === 1) {
            return [str_pad($code, 5, '0', STR_PAD_LEFT), Countries::FRANCE, null];
        }

        $commune = Text::clean($place) ?? '(vide)';
        if ($code === null) {
            return [null, Countries::FRANCE, sprintf('code postal absent pour une adresse française (commune « %s ») : 4 ou 5 chiffres attendus.', $commune)];
        }

        return [$code, Countries::FRANCE, sprintf('code postal « %s » invalide pour une adresse française (commune « %s ») : 4 ou 5 chiffres attendus.', $code, $commune)];
    }

    /**
     * Date ISO (AAAA-MM-JJ) d'une valeur de cellule : nombre de série Excel (calendrier 1900, jour 0 = 30/12/1899),
     * date « JJ/MM/AAAA » ou date ISO. Null si la valeur est illisible.
     */
    public static function date(string $value): ?string
    {
        if (preg_match('/\A([0-9]+)(?:\.[0-9]+)?\z/', $value, $match) === 1) {
            $serial = (int) $match[1];
            if ($serial < self::SERIAL_MIN || $serial > self::SERIAL_MAX) {
                return null;
            }

            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . $serial . ' days')->format('Y-m-d');
        }
        if (preg_match('~\A([0-3]?[0-9])/([01]?[0-9])/([0-9]{4})\z~', $value, $match) === 1) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } elseif (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[T ][0-9:.]+Z?)?\z/', $value, $match) === 1) {
            [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } else {
            return null;
        }
        if (!checkdate($month, $day, $year) || $year < 2000 || $year > 2100) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Ligne d'en-tête, première ligne de données, champ de chaque colonne, intitulés lus (pour data/source.json).
     *
     * @param array<int, array<int, string>> $rows
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $merges
     * @param list<string> $expected
     *
     * @return array{0: int, 1: int, 2: array<int, string>, 3: list<string>}
     *
     * @throws SourceError
     */
    private static function header(array $rows, array $merges, string $label, array $expected): array
    {
        $headerRow = null;
        foreach ($rows as $number => $cells) {
            if ($number > self::HEADER_SEARCH_ROWS) {
                break;
            }
            foreach ($cells as $cell) {
                if (Columns::field($cell) === Columns::MARKER) {
                    $headerRow = $number;
                    break 2;
                }
            }
        }
        if ($headerRow === null) {
            throw new SourceError(sprintf(
                '%s : ligne d\'en-tête introuvable dans les %d premières lignes (aucun intitulé « Nom commercial »). Intitulés reconnus : %s.',
                $label,
                self::HEADER_SEARCH_ROWS,
                Columns::describe(),
            ));
        }

        $top = $rows[$headerRow];
        $sub = $rows[$headerRow + 1] ?? [];
        $last = max(array_merge(array_keys($top), array_keys($sub)));
        $parents = [];
        $inherited = null;
        for ($index = 0; $index <= $last; $index++) {
            $parent = Text::header($top[$index] ?? null) ?? self::merged($rows, $merges, $headerRow, $index);
            if ($parent === null && isset($sub[$index])) {
                $parent = $inherited;
            }
            if ($parent !== null) {
                $parents[$index] = $parent;
                $inherited = $parent;
            }
        }

        // La ligne 2 est une ligne d'en-tête si chacune de ses cellules complète un intitulé reconnu.
        $twoRows = $sub !== [];
        foreach ($sub as $index => $cell) {
            if (!isset($parents[$index]) || Columns::field($parents[$index], $cell) === null) {
                $twoRows = false;
                break;
            }
        }

        $mapping = [];
        $columns = [];
        $read = [];
        foreach ($parents as $index => $parent) {
            $child = $twoRows ? Text::header($sub[$index] ?? null) : null;
            $read[$index] = $parent . ($child !== null ? Columns::SEPARATOR . $child : '');
        }
        foreach ($read as $index => $header) {
            $field = Columns::field($parents[$index], $twoRows ? ($sub[$index] ?? null) : null);
            if ($field === null) {
                throw new SourceError(sprintf(
                    "%s, colonne %s : intitulé « %s » inconnu. Intitulés lus : « %s ».\nVérifier le fichier, puis ajouter l'intitulé dans tools/src/Columns.php. Intitulés reconnus : %s.",
                    $label,
                    XlsxReader::columnLetter($index),
                    $header,
                    implode(' », « ', $read),
                    Columns::describe(),
                ));
            }
            if (\in_array($field, $mapping, true)) {
                throw new SourceError(sprintf('%s, colonne %s : deux colonnes donnent le même champ « %s » (« %s »).', $label, XlsxReader::columnLetter($index), $field, $header));
            }
            $mapping[$index] = $field;
            $columns[] = $header;
        }

        if (array_values($mapping) !== $expected) {
            throw new SourceError(sprintf(
                "%s : colonnes différentes de celles attendues pour ce fichier.\nLues : %s.\nAttendues : %s.\nIntitulés lus : « %s ».",
                $label,
                implode(', ', $mapping),
                implode(', ', $expected),
                implode(' », « ', $read),
            ));
        }

        return [$headerRow, $headerRow + ($twoRows ? 2 : 1), $mapping, $columns];
    }

    /**
     * Valeur de la plage fusionnée qui couvre la cellule (ligne, colonne), lue dans sa première cellule.
     *
     * @param array<int, array<int, string>> $rows
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $merges
     */
    private static function merged(array $rows, array $merges, int $row, int $column): ?string
    {
        foreach ($merges as [$firstRow, $firstColumn, $lastRow, $lastColumn]) {
            if ($row >= $firstRow && $row <= $lastRow && $column >= $firstColumn && $column <= $lastColumn) {
                return Text::header($rows[$firstRow][$firstColumn] ?? null);
            }
        }

        return null;
    }

    /**
     * Date écrite au-dessus de l'en-tête (« Liste à jour au 12/11/2026 »), au format AAAA-MM-JJ, ou null.
     *
     * @param array<int, array<int, string>> $rows
     */
    private static function statedDate(array $rows, int $headerRow): ?string
    {
        $found = null;
        foreach ($rows as $number => $cells) {
            if ($number >= $headerRow) {
                break;
            }
            foreach ($cells as $cell) {
                if (preg_match('~\b([0-3]?[0-9])/([01]?[0-9])/(20[0-9]{2})\b~', $cell, $match) === 1
                    && checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
                    $date = sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
                    $found = $found === null || $date > $found ? $date : $found;
                }
            }
        }

        return $found;
    }
}
