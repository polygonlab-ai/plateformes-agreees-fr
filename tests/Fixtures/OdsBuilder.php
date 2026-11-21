<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests\Fixtures;

/**
 * Fabrique des classeurs ODS d'essai, de même structure que les fichiers de la DGFiP et que ceux de XlsxBuilder :
 * une feuille, en-tête sur deux lignes, « Adresse de l'établissement principal » fusionnée sur B1:D1, sous-colonnes en
 * ligne 2, date de délivrance en colonne G (dans la liste des plateformes immatriculées seulement).
 *
 * Le fichier est écrit comme le fait un tableur : cellules vides réunies par « table:number-columns-repeated », fin de
 * ligne et fin de feuille en cellules et lignes vides répétées des milliers de fois, cellules couvertes par une fusion
 * en « table:covered-table-cell », un paragraphe « text:p » par ligne de texte, espaces en « text:s », un texte sur
 * deux dans un « text:span ».
 *
 * Valeur d'une cellule : un texte est une cellule de texte ; un entier est un nombre ; et, comme dans XlsxBuilder,
 * « num:<valeur> » force un nombre brut, « inline: » un texte, « empty: » une cellule présente, stylée mais sans
 * valeur. En plus, propres à ce format : « date:AAAA-MM-JJ » (cellule de date, affichée JJ/MM/AAAA),
 * « bool:true » ou « bool:false », et « xml:<contenu> » (contenu de cellule écrit tel quel : paragraphes, morceaux,
 * liens, commentaire).
 * Aucune donnée réelle : noms fictifs, adresses de courriel en example.com.
 */
final class OdsBuilder
{
    public const MIME_TYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    /** Nombre de colonnes et de lignes d'une feuille de tableur : la fin de chaque ligne et de la feuille les atteint. */
    public const SHEET_COLUMNS = 16_384;
    public const SHEET_ROWS = 1_048_576;

    /** Colonne de la date de délivrance (G) dans la structure de la DGFiP. */
    private const DATE_COLUMN = 6;

    /**
     * Écrit un fichier d'essai au format de la DGFiP (mêmes plateformes et mêmes fusions que XlsxBuilder::file()) et
     * rend son chemin. Une date de délivrance donnée en nombre de série devient une cellule de date : c'est ainsi
     * qu'un tableur enregistre en ODS la date qu'il enregistre en nombre de série en XLSX.
     *
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $platforms
     */
    public static function file(string $path, array $platforms, bool $withDate = true): string
    {
        $rows = [];
        foreach (XlsxBuilder::list($platforms, $withDate) as $number => $row) {
            $date = $row[self::DATE_COLUMN] ?? null;
            if ($number >= 2 && \is_int($date)) {
                $row[self::DATE_COLUMN] = 'date:' . self::serialDate($date);
            }
            $rows[] = $row;
        }

        return self::write($path, $rows, 'Feuil1', XlsxBuilder::merges($withDate));
    }

    /**
     * Date ISO d'un nombre de série de tableur (jour 0 = 30/12/1899).
     */
    public static function serialDate(int $serial): string
    {
        return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . $serial . ' days')->format('Y-m-d');
    }

    /**
     * Écrit le classeur et rend son chemin.
     *
     * @param list<array<int<0, max>, string|int|null>> $rows cellules indexées par colonne (0 = A), trous permis
     * @param list<string> $merges plages fusionnées (« B1:D1 »)
     * @param list<string> $otherSheets noms de feuilles ajoutées après la première (une cellule de texte chacune)
     */
    public static function write(string $path, array $rows, string $sheet = 'Liste', array $merges = [], array $otherSheets = []): string
    {
        $tables = self::table($sheet, self::rows($rows, $merges));
        foreach ($otherSheets as $other) {
            $tables .= self::table($other, '<table:table-row>' . self::cell('Autre feuille, jamais lue') . '</table:table-row>');
        }

        return self::archive($path, self::document($tables));
    }

    /**
     * Une feuille : ses colonnes, puis ses lignes (XML déjà écrit).
     */
    public static function table(string $name, string $rows): string
    {
        return '<table:table table:name="' . self::escape($name) . '" table:style-name="ta1">'
            . '<table:table-column table:style-name="co1" table:number-columns-repeated="' . self::SHEET_COLUMNS . '" table:default-cell-style-name="Default"/>'
            . $rows . '</table:table>';
    }

    /**
     * Partie « content.xml » complète autour des feuilles données.
     */
    public static function document(string $tables): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0"'
            . ' office:version="1.3">'
            . '<office:scripts/>'
            . '<office:automatic-styles>'
            . '<style:style style:name="co1" style:family="table-column"><style:table-column-properties style:column-width="2.258cm"/></style:style>'
            . '<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style>'
            . '<style:style style:name="T1" style:family="text"><style:text-properties/></style:style>'
            . '</office:automatic-styles>'
            . '<office:body><office:spreadsheet>'
            . '<table:calculation-settings table:automatic-find-labels="false"/>'
            . $tables
            . '<table:named-expressions/>'
            . '</office:spreadsheet></office:body></office:document-content>';
    }

    /**
     * Écrit l'archive ODS autour d'une partie « content.xml » et rend son chemin. « mimetype » est la première partie,
     * non compressée, comme le veut le format ; une chaîne vide l'omet.
     */
    public static function archive(string $path, string $content, string $mimeType = self::MIME_TYPE): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Création impossible : ' . $path);
        }
        if ($mimeType !== '') {
            $zip->addFromString('mimetype', $mimeType);
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        }
        $zip->addFromString('content.xml', $content);
        $zip->addFromString(
            'META-INF/manifest.xml',
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
            . '<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="' . self::escape($mimeType) . '"/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            . '</manifest:manifest>',
        );
        $zip->close();

        return $path;
    }

    /**
     * Une cellule : « <table:table-cell …>…</table:table-cell> », selon la forme de sa valeur (voir l'en-tête de la
     * classe). $attributes s'ajoute à la balise (fusion, répétition).
     */
    public static function cell(string|int|null $value, string $attributes = ''): string
    {
        if ($value === null || $value === '') {
            return '<table:table-cell' . $attributes . '/>';
        }
        if (\is_int($value)) {
            return self::number((string) $value, $attributes);
        }
        if (str_starts_with($value, 'num:')) {
            return self::number(substr($value, 4), $attributes);
        }
        if ($value === 'empty:') {
            return '<table:table-cell table:style-name="ce1"' . $attributes . '/>';
        }
        if (str_starts_with($value, 'date:')) {
            $date = substr($value, 5);
            $shown = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

            return '<table:table-cell table:style-name="ce2" office:value-type="date" office:date-value="' . self::escape($date) . '" calcext:value-type="date"' . $attributes . '>'
                . '<text:p>' . ($shown === false ? self::escape($date) : $shown->format('d/m/Y')) . '</text:p></table:table-cell>';
        }
        if (str_starts_with($value, 'bool:')) {
            $true = substr($value, 5) === 'true';

            return '<table:table-cell office:value-type="boolean" office:boolean-value="' . ($true ? 'true' : 'false') . '" calcext:value-type="boolean"' . $attributes . '>'
                . '<text:p>' . ($true ? 'VRAI' : 'FAUX') . '</text:p></table:table-cell>';
        }
        if (str_starts_with($value, 'xml:')) {
            return '<table:table-cell office:value-type="string" calcext:value-type="string"' . $attributes . '>' . substr($value, 4) . '</table:table-cell>';
        }
        $text = str_starts_with($value, 'inline:') ? substr($value, 7) : $value;
        $paragraphs = '';
        foreach (explode("\n", $text) as $line) {
            // Un texte sur deux dans un « text:span », pour couvrir les deux formes.
            $paragraphs .= \strlen($line) % 2 === 0
                ? '<text:p>' . self::text($line) . '</text:p>'
                : '<text:p><text:span text:style-name="T1">' . self::text($line) . '</text:span></text:p>';
        }

        return '<table:table-cell office:value-type="string" calcext:value-type="string"' . $attributes . '>' . $paragraphs . '</table:table-cell>';
    }

    /**
     * Texte d'un paragraphe, écrit comme un tableur : espace de début ou de fin, et espaces à la suite d'un autre,
     * en « text:s » ; tabulation en « text:tab ».
     */
    public static function text(string $text): string
    {
        $pieces = preg_split('/( +|\t)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $xml = '';
        foreach ($pieces as $rank => $piece) {
            if ($piece === "\t") {
                $xml .= '<text:tab/>';
            } elseif (trim($piece, ' ') !== '') {
                $xml .= self::escape($piece);
            } else {
                // Au milieu du texte, le premier espace s'écrit tel quel ; au début et à la fin, aucun.
                $kept = $rank === 0 || $rank === \count($pieces) - 1 ? 0 : 1;
                $extra = \strlen($piece) - $kept;
                $xml .= str_repeat(' ', $kept) . ($extra > 0 ? '<text:s' . ($extra > 1 ? ' text:c="' . $extra . '"' : '') . '/>' : '');
            }
        }

        return $xml;
    }

    /**
     * Cellule de nombre : la valeur enregistrée dans « office:value », et un texte affiché différent (« 6 200 » pour
     * 6200) pour qu'un test prouve que c'est bien la valeur enregistrée qui est lue.
     */
    private static function number(string $raw, string $attributes): string
    {
        $shown = is_numeric($raw) ? number_format((float) $raw, 0, ',', ' ') : $raw;

        return '<table:table-cell office:value-type="float" office:value="' . self::escape($raw) . '" calcext:value-type="float"' . $attributes . '>'
            . '<text:p>' . self::escape($shown) . '</text:p></table:table-cell>';
    }

    /**
     * Lignes de la feuille : cellules, fusions, cellules et lignes vides réunies, fin de feuille répétée.
     *
     * @param list<array<int<0, max>, string|int|null>> $rows
     * @param list<string> $merges
     */
    private static function rows(array $rows, array $merges): string
    {
        $spans = [];
        $covered = [];
        foreach ($merges as $merge) {
            if (preg_match('/\A([A-Z]{1,3})([0-9]+):([A-Z]{1,3})([0-9]+)\z/', $merge, $match) !== 1) {
                throw new \InvalidArgumentException('Plage fusionnée illisible : ' . $merge);
            }
            [$firstRow, $firstColumn, $lastRow, $lastColumn] = [(int) $match[2] - 1, self::index($match[1]), (int) $match[4] - 1, self::index($match[3])];
            $spans[$firstRow][$firstColumn] = ' table:number-columns-spanned="' . ($lastColumn - $firstColumn + 1) . '" table:number-rows-spanned="' . ($lastRow - $firstRow + 1) . '"';
            for ($row = $firstRow; $row <= $lastRow; $row++) {
                for ($column = $firstColumn; $column <= $lastColumn; $column++) {
                    if ($row !== $firstRow || $column !== $firstColumn) {
                        $covered[$row][$column] = true;
                    }
                }
            }
        }

        $xml = '';
        $emptyRows = 0;
        foreach ($rows as $number => $cells) {
            $last = max([-1, ...array_keys(array_filter($cells, static fn(string|int|null $value): bool => $value !== null && $value !== '')), ...array_keys($spans[$number] ?? []), ...array_keys($covered[$number] ?? [])]);
            if ($last < 0) {
                $emptyRows++;

                continue;
            }
            $xml .= self::emptyRows($emptyRows);
            $emptyRows = 0;
            $line = '';
            $emptyCells = 0;
            for ($column = 0; $column <= $last; $column++) {
                $value = $cells[$column] ?? null;
                $isCovered = isset($covered[$number][$column]);
                $span = $spans[$number][$column] ?? '';
                if (!$isCovered && $span === '' && ($value === null || $value === '')) {
                    $emptyCells++;

                    continue;
                }
                $line .= self::emptyCells($emptyCells);
                $emptyCells = 0;
                $line .= $isCovered ? '<table:covered-table-cell/>' : self::cell($value, $span);
            }
            $xml .= '<table:table-row table:style-name="ro1">' . $line . self::emptyCells(self::SHEET_COLUMNS - $last - 1) . '</table:table-row>';
        }

        return $xml . self::emptyRows(self::SHEET_ROWS - \count($rows) + $emptyRows);
    }

    private static function emptyCells(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return '<table:table-cell' . ($count > 1 ? ' table:number-columns-repeated="' . $count . '"' : '') . '/>';
    }

    private static function emptyRows(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return '<table:table-row table:style-name="ro1"' . ($count > 1 ? ' table:number-rows-repeated="' . $count . '"' : '') . '>'
            . self::emptyCells(self::SHEET_COLUMNS) . '</table:table-row>';
    }

    private static function index(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (\ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
