<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests\Fixtures;

/**
 * Fabrique des classeurs XLSX d'essai, de même structure que les fichiers de la DGFiP (constatée le 14/11/2026) :
 * une feuille, en-tête sur deux lignes, « Adresse de l'établissement principal » fusionnée sur B1:D1 avec des sauts
 * de ligne et un mot coupé, sous-colonnes en ligne 2, date de délivrance en nombre de série Excel (colonne G, dans la
 * liste des plateformes immatriculées seulement).
 *
 * Les textes sont écrits en chaînes partagées (comme Excel), sauf ceux marqués « inline: » (chaînes en ligne) ;
 * un entier est écrit comme un nombre, « num:<valeur> » force un nombre brut (par exemple « 1.23456789E8 ») et
 * « empty: » écrit une cellule présente, stylée mais sans valeur (« <c r="AMI35" s="0"/> »).
 * Aucune donnée réelle : noms fictifs, adresses de courriel en example.com.
 */
final class XlsxBuilder
{
    /** Intitulés de la ligne 1 (la colonne G n'existe que dans la liste des plateformes immatriculées). */
    public const HEADER = ['Nom commercial', "Adresse\nde l\u{2019}établis-\nsement principal", null, null, 'Site internet', 'Courriel de contact', "Date de délivrance du numéro\nd'immatriculation"];

    /** Sous-colonnes de la ligne 2. */
    public const SUB_HEADER = [null, 'Voie', 'Code postal', 'Commune (Pays le cas échéant)'];

    /** Plage fusionnée de l'intitulé de l'adresse. */
    public const MERGE = 'B1:D1';

    /**
     * Toutes les fusions d'un fichier réel : A1:A2, B1:D1, E1:E2, F1:F2, et G1:G2 s'il a la colonne de date.
     *
     * @return list<string>
     */
    public static function merges(bool $withDate = true): array
    {
        $merges = ['A1:A2', self::MERGE, 'E1:E2', 'F1:F2'];
        if ($withDate) {
            $merges[] = 'G1:G2';
        }

        return $merges;
    }

    /**
     * Lignes d'un fichier d'essai : en-tête sur deux lignes, puis les plateformes.
     *
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $platforms
     *        nom commercial, voie, code postal, commune ou pays, site internet, courriel, date (nombre de série ou texte)
     *
     * @return list<list<string|int|null>>
     */
    public static function list(array $platforms, bool $withDate = true): array
    {
        $width = $withDate ? 7 : 6;
        $rows = [\array_slice(self::HEADER, 0, $width), self::SUB_HEADER];
        foreach ($platforms as $platform) {
            $row = [];
            for ($column = 0; $column < $width; $column++) {
                $row[] = $platform[$column] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Plateforme d'essai complète : nom, adresse française, site, courriel en example.com, date de délivrance.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int}
     */
    public static function platform(string $name, int $serial = 46002): array
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));

        return [$name, '1 rue de l\'Exemple', '75001', 'Paris', 'https://www.example.com/' . trim($slug, '-'), trim($slug, '-') . '@example.com', $serial];
    }

    /**
     * Écrit un fichier d'essai au format de la DGFiP (en-tête fusionné B1:D1) et rend son chemin.
     *
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $platforms
     */
    public static function file(string $path, array $platforms, bool $withDate = true): string
    {
        return self::write($path, self::list($platforms, $withDate), 'Feuil1', self::merges($withDate));
    }

    /**
     * Écrit le classeur et rend son chemin.
     *
     * @param list<array<int<0, max>, string|int|null>> $rows cellules indexées par colonne (0 = A), trous permis
     * @param list<string> $merges plages fusionnées (« B1:D1 »)
     */
    public static function write(string $path, array $rows, string $sheet = 'Liste', array $merges = []): string
    {
        $strings = [];
        $index = [];
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $number = $r + 1;
            $xmlCells = '';
            foreach ($cells as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $reference = self::column($c) . $number;
                if (\is_int($value)) {
                    $xmlCells .= '<c r="' . $reference . '"><v>' . $value . '</v></c>';
                } elseif (str_starts_with($value, 'num:')) {
                    $xmlCells .= '<c r="' . $reference . '"><v>' . self::escape(substr($value, 4)) . '</v></c>';
                } elseif ($value === 'empty:') {
                    $xmlCells .= '<c r="' . $reference . '" s="0"/>';
                } elseif (str_starts_with($value, 'inline:')) {
                    $xmlCells .= '<c r="' . $reference . '" t="inlineStr"><is><t>' . self::escape(substr($value, 7)) . '</t></is></c>';
                } else {
                    if (!isset($index[$value])) {
                        $index[$value] = \count($strings);
                        $strings[] = $value;
                    }
                    $xmlCells .= '<c r="' . $reference . '" t="s"><v>' . $index[$value] . '</v></c>';
                }
            }
            if ($xmlCells !== '') {
                $sheetRows .= '<row r="' . $number . '">' . $xmlCells . '</row>';
            }
        }

        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relations = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $shared = '';
        foreach ($strings as $string) {
            // Une chaîne sur deux en texte enrichi (éléments « r »), pour couvrir les deux formes.
            $shared .= \strlen($string) % 2 === 0
                ? '<si><t xml:space="preserve">' . self::escape($string) . '</t></si>'
                : '<si><r><t xml:space="preserve">' . self::escape($string) . '</t></r></si>';
        }

        $mergeXml = '';
        if ($merges !== []) {
            $mergeXml = '<mergeCells count="' . \count($merges) . '">';
            foreach ($merges as $merge) {
                $mergeXml .= '<mergeCell ref="' . self::escape($merge) . '"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="' . $main . '" xmlns:r="' . $relations . '"><sheets>'
                . '<sheet name="' . self::escape($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                . '</Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<sst xmlns="' . $main . '" count="' . \count($strings) . '" uniqueCount="' . \count($strings) . '">' . $shared . '</sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="' . $main . '"><sheetData>' . $sheetRows . '</sheetData>' . $mergeXml . '</worksheet>',
        ];

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Création impossible : ' . $path);
        }
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private static function column(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $letters = \chr(65 + $rest) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
