<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Lecteur minimal de classeur XLSX (Office Open XML), sans dépendance : ZipArchive et XMLReader suffisent.
 *
 * Il lit la première feuille du classeur et rend ses lignes sous forme de texte, ainsi que ses plages de cellules
 * fusionnées (une cellule fusionnée n'a de valeur que dans sa première cellule, en haut à gauche) : chaînes partagées, chaînes
 * en ligne, formules (valeur calculée enregistrée), booléens et nombres. Un nombre entier enregistré en notation
 * scientifique (« 1.23456789E8 ») est rendu en chiffres. Les dates ne sont pas converties : aucune colonne lue
 * n'en contient.
 *
 * Garde-fous : taille décompressée bornée, aucune entité XML substituée, aucun accès réseau pendant la lecture.
 */
final class XlsxReader
{
    /** Taille maximale décompressée d'une partie du classeur (octets). */
    public const MAX_PART_SIZE = 50_000_000;

    /** Nombre maximal de lignes lues dans la feuille. */
    public const MAX_ROWS = 100_000;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Nom de la première feuille, ses lignes (index de ligne à partir de 1, colonnes à partir de 0) et ses plages
     * fusionnées (ligne et colonne de début, ligne et colonne de fin, mêmes index).
     *
     * @return array{sheet: string, sheets: int, rows: array<int, array<int, string>>, merges: list<array{0: int, 1: int, 2: int, 3: int}>}
     *
     * @throws SourceError
     */
    public static function read(string $path, string $label): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new SourceError('Extension PHP zip absente : lecture des fichiers XLSX impossible.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new SourceError(sprintf('%s : fichier introuvable ou illisible.', $label));
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new SourceError(sprintf('%s : ce n\'est pas un classeur XLSX (archive illisible).', $label));
        }

        try {
            [$sheetName, $sheetPath, $sheetCount] = self::firstSheet($zip, $label);
            $strings = self::sharedStrings($zip, $label);
            [$rows, $merges] = self::rows(self::part($zip, $sheetPath, $label), $strings, $label);
        } finally {
            $zip->close();
        }

        return ['sheet' => $sheetName, 'sheets' => $sheetCount, 'rows' => $rows, 'merges' => $merges];
    }

    /**
     * Indice de colonne (à partir de 0) d'une référence de cellule : « C12 » ou « C » donnent 2.
     */
    public static function columnIndex(string $reference): ?int
    {
        if (preg_match('/\A([A-Z]{1,3})[0-9]*\z/', $reference, $match) !== 1) {
            return null;
        }
        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Lettre(s) d'une colonne (à partir de 0) : 2 donne « C ».
     */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $letters = chr(65 + $rest) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /**
     * @return array{0: string, 1: string, 2: int} nom de la feuille, chemin de sa partie, nombre de feuilles
     *
     * @throws SourceError
     */
    private static function firstSheet(\ZipArchive $zip, string $label): array
    {
        $workbook = self::xml(self::part($zip, 'xl/workbook.xml', $label), $label);
        $workbook->registerXPathNamespace('m', self::NS_MAIN);
        $sheets = $workbook->xpath('/m:workbook/m:sheets/m:sheet');
        if (!is_array($sheets) || $sheets === []) {
            throw new SourceError(sprintf('%s : aucune feuille dans le classeur.', $label));
        }
        $first = $sheets[0];
        $name = (string) $first['name'];
        $attributes = $first->attributes(self::NS_REL);
        $relationId = $attributes !== null ? (string) $attributes['id'] : '';

        $relations = self::xml(self::part($zip, 'xl/_rels/workbook.xml.rels', $label), $label);
        $target = null;
        foreach ($relations->children() ?? [] as $relation) {
            if ((string) $relation['Id'] === $relationId) {
                $target = (string) $relation['Target'];
                break;
            }
        }
        if ($target === null || $target === '') {
            throw new SourceError(sprintf('%s : feuille « %s » introuvable dans le classeur.', $label, $name));
        }
        $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        if (preg_match('~\Axl/worksheets/[A-Za-z0-9_.-]+\.xml\z~', $target) !== 1) {
            throw new SourceError(sprintf('%s : emplacement de feuille inattendu (%s).', $label, $target));
        }

        return [$name, $target, \count($sheets)];
    }

    /**
     * @return list<string>
     *
     * @throws SourceError
     */
    private static function sharedStrings(\ZipArchive $zip, string $label): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $reader = self::reader(self::part($zip, 'xl/sharedStrings.xml', $label), $label);
        $strings = [];
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings[] = self::text($reader->readOuterXml());
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param list<string> $strings
     *
     * @return array{0: array<int, array<int, string>>, 1: list<array{0: int, 1: int, 2: int, 3: int}>}
     *
     * @throws SourceError
     */
    private static function rows(string $xml, array $strings, string $label): array
    {
        $reader = self::reader($xml, $label);
        $rows = [];
        $merges = [];
        $rowNumber = 0;
        $column = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'row') {
                $declared = $reader->getAttribute('r');
                $rowNumber = $declared !== null && ctype_digit($declared) ? (int) $declared : $rowNumber + 1;
                $column = 0;
                if (\count($rows) >= self::MAX_ROWS) {
                    throw new SourceError(sprintf('%s : plus de %d lignes, fichier refusé.', $label, self::MAX_ROWS));
                }
                continue;
            }
            if ($reader->localName === 'mergeCell') {
                $range = (string) $reader->getAttribute('ref');
                if (preg_match('/\A([A-Z]{1,3})([0-9]+):([A-Z]{1,3})([0-9]+)\z/', $range, $match) !== 1) {
                    throw new SourceError(sprintf('%s : plage fusionnée illisible (%s).', $label, $range));
                }
                $merges[] = [(int) $match[2], (int) self::columnIndex($match[1]), (int) $match[4], (int) self::columnIndex($match[3])];
                continue;
            }
            if ($reader->localName !== 'c') {
                continue;
            }
            $reference = $reader->getAttribute('r');
            $index = $reference !== null ? self::columnIndex($reference) : null;
            $index ??= $column;
            $column = $index + 1;
            $type = $reader->getAttribute('t') ?? 'n';
            $value = self::cellValue($reader->readOuterXml(), $type, $strings, $label, $rowNumber);
            if ($value !== null && $value !== '') {
                $rows[$rowNumber][$index] = $value;
            }
        }
        $reader->close();
        ksort($rows);

        return [$rows, $merges];
    }

    /**
     * @param list<string> $strings
     *
     * @throws SourceError
     */
    private static function cellValue(string $cellXml, string $type, array $strings, string $label, int $row): ?string
    {
        $cell = self::xml($cellXml, $label);
        $cell->registerXPathNamespace('m', self::NS_MAIN);
        $values = $cell->xpath('m:v');
        $raw = is_array($values) && $values !== [] ? (string) $values[0] : null;

        switch ($type) {
            case 's':
                if ($raw === null || !ctype_digit($raw) || !isset($strings[(int) $raw])) {
                    throw new SourceError(sprintf('%s, ligne %d : chaîne partagée introuvable.', $label, $row));
                }

                return $strings[(int) $raw];
            case 'inlineStr':
                $inline = $cell->xpath('m:is');

                return is_array($inline) && $inline !== [] ? self::text((string) $inline[0]->asXML()) : null;
            case 'b':
                return $raw === null ? null : ($raw === '1' ? 'VRAI' : 'FAUX');
            case 'e':
                throw new SourceError(sprintf('%s, ligne %d : cellule en erreur (%s).', $label, $row, (string) $raw));
            case 'str':
                return $raw;
            default:
                return $raw === null ? null : self::number($raw);
        }
    }

    /**
     * Nombre enregistré : un entier est rendu en chiffres, même s'il est écrit en notation scientifique.
     * Même règle pour un classeur ODS (OdsReader) : les deux lecteurs rendent la même valeur.
     */
    public static function number(string $raw): string
    {
        if (is_numeric($raw) && preg_match('/[eE.]/', $raw) === 1) {
            $float = (float) $raw;
            if (is_finite($float) && floor($float) === $float && abs($float) < 1e15) {
                return sprintf('%.0f', $float);
            }
        }

        return $raw;
    }

    /**
     * Texte d'un élément « si » ou « is » : concaténation des éléments « t », sans la prononciation (« rPh »).
     *
     * @throws SourceError
     */
    private static function text(string $xml): string
    {
        // Noms locaux : un élément extrait de la feuille peut avoir perdu la déclaration de son espace de noms.
        $node = self::xml($xml, 'texte');
        $parts = $node->xpath("*[local-name()='t'] | *[local-name()='r']/*[local-name()='t']");
        $text = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            $text .= (string) $part;
        }

        return $text;
    }

    /**
     * @throws SourceError
     */
    private static function part(\ZipArchive $zip, string $name, string $label): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new SourceError(sprintf('%s : partie « %s » absente du classeur.', $label, $name));
        }
        if ($stat['size'] > self::MAX_PART_SIZE) {
            throw new SourceError(sprintf('%s : partie « %s » trop volumineuse une fois décompressée.', $label, $name));
        }
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new SourceError(sprintf('%s : partie « %s » illisible.', $label, $name));
        }

        return $content;
    }

    /**
     * @throws SourceError
     */
    private static function xml(string $xml, string $label): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($element === false) {
            throw new SourceError(sprintf('%s : XML invalide dans le classeur.', $label));
        }

        return $element;
    }

    /**
     * @throws SourceError
     */
    private static function reader(string $xml, string $label): \XMLReader
    {
        $reader = \XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        if (!$reader instanceof \XMLReader) {
            throw new SourceError(sprintf('%s : XML invalide dans le classeur.', $label));
        }

        return $reader;
    }
}
