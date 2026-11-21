<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Lecteur minimal de classeur ODS (OpenDocument Spreadsheet), sans dépendance : ZipArchive et XMLReader suffisent.
 *
 * Il rend exactement ce que rend XlsxReader pour le même tableau : la première feuille, ses lignes sous forme de
 * texte et ses plages de cellules fusionnées. La DGFiP publie chaque liste en XLSX et en ODS : quand le fichier XLSX
 * n'est plus servi, la même liste se lit ici.
 *
 * Ce que le format a de particulier, et ce qui en est fait :
 *   - cellules et lignes répétées (« table:number-columns-repeated », « table:number-rows-repeated ») : une cellule
 *     ou une ligne vide répétée ne fait qu'avancer la position (la fin d'une feuille est une ligne vide répétée un
 *     million de fois, jamais dépliée) ; une cellule ou une ligne remplie est recopiée autant de fois ;
 *   - cellules fusionnées : la première porte la valeur et l'étendue (« table:number-columns-spanned »,
 *     « table:number-rows-spanned »), les autres sont des « table:covered-table-cell », sans valeur ;
 *   - type de la cellule (« office:value-type ») : un nombre, un pourcentage ou un montant sont lus dans
 *     « office:value » (valeur enregistrée, pas le texte affiché « 6 200 »), un booléen donne VRAI ou FAUX, une date
 *     est lue dans « office:date-value » et rendue au format AAAA-MM-JJ (l'heure n'est gardée que si elle n'est pas
 *     minuit) ; XlsxReader rend pour la même date son nombre de série, et ListReader::date() lit les deux ;
 *   - texte : paragraphes « text:p » réunis par un saut de ligne, morceaux « text:span » et liens « text:a » recollés,
 *     « text:s » rendu en espaces, « text:tab » en tabulation, « text:line-break » en saut de ligne ; les commentaires
 *     de cellule (« office:annotation »), les notes et les objets dessinés ne font pas partie de la valeur.
 *
 * Garde-fous : taille décompressée bornée, nombre de lignes et de colonnes borné, aucune entité XML substituée,
 * aucun accès réseau pendant la lecture.
 */
final class OdsReader
{
    /** Type déclaré dans la partie « mimetype » d'un classeur ODS (un modèle porte le suffixe « -template »). */
    public const MIME_TYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    /** Nombre maximal de colonnes d'une ligne remplie (celui d'une feuille XLSX). */
    public const MAX_COLUMNS = 16_384;

    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const NS_CALCEXT = 'urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0';

    /** Éléments du texte dont le contenu n'est pas affiché dans la cellule : jamais lus. */
    private const HIDDEN_TEXT = ['note', 'ruby-text', 'tracked-changes', 'change', 'change-start', 'change-end'];

    /**
     * Nom de la première feuille, ses lignes (index de ligne à partir de 1, colonnes à partir de 0) et ses plages
     * fusionnées (ligne et colonne de début, ligne et colonne de fin, mêmes index) : même forme que XlsxReader::read().
     *
     * @return array{sheet: string, sheets: int, rows: array<int, array<int, string>>, merges: list<array{0: int, 1: int, 2: int, 3: int}>}
     *
     * @throws SourceError
     */
    public static function read(string $path, string $label): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new SourceError('Extension PHP zip absente : lecture des fichiers ODS impossible.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new SourceError(sprintf('%s : fichier introuvable ou illisible.', $label));
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new SourceError(sprintf('%s : ce n\'est pas un classeur ODS (archive illisible).', $label));
        }

        try {
            $type = $zip->locateName('mimetype') === false ? '' : trim(self::part($zip, 'mimetype', $label));
            if ($type !== self::MIME_TYPE && $type !== self::MIME_TYPE . '-template') {
                throw new SourceError(sprintf('%s : ce n\'est pas un classeur ODS (type « %s »).', $label, $type === '' ? 'absent' : mb_strimwidth($type, 0, 80, '…')));
            }
            $content = self::part($zip, 'content.xml', $label);
        } finally {
            $zip->close();
        }

        return self::document($content, $label);
    }

    /**
     * @return array{sheet: string, sheets: int, rows: array<int, array<int, string>>, merges: list<array{0: int, 1: int, 2: int, 3: int}>}
     *
     * @throws SourceError
     */
    private static function document(string $xml, string $label): array
    {
        $reader = $xml === '' ? false : \XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        if (!$reader instanceof \XMLReader) {
            throw new SourceError(sprintf('%s : XML invalide dans le classeur.', $label));
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $sheet = null;
            $sheets = 0;
            $rows = [];
            $merges = [];
            $spreadsheet = null;
            $more = $reader->read();
            while ($more) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    if ($spreadsheet === null && $reader->namespaceURI === self::NS_OFFICE && $reader->localName === 'spreadsheet') {
                        $spreadsheet = $reader->depth;
                    } elseif ($spreadsheet !== null && $reader->depth === $spreadsheet + 1) {
                        // Enfants directs du classeur : les feuilles, et des réglages qui ne sont pas des données.
                        if ($reader->namespaceURI === self::NS_TABLE && $reader->localName === 'table') {
                            $sheets++;
                            if ($sheets === 1) {
                                $sheet = (string) $reader->getAttributeNs('name', self::NS_TABLE);
                                [$rows, $merges] = self::table($reader, $label);
                            }
                        }
                        $more = $reader->next();

                        continue;
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $spreadsheet !== null && $reader->depth === $spreadsheet) {
                    break;
                }
                $more = $reader->read();
            }
            // Une simple mise en garde de l'analyseur n'invalide pas le fichier ; une erreur, si.
            $invalid = array_filter(libxml_get_errors(), static fn(\LibXMLError $error): bool => $error->level !== LIBXML_ERR_WARNING) !== [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }
        if ($invalid) {
            throw new SourceError(sprintf('%s : XML invalide dans le classeur.', $label));
        }
        if ($sheet === null) {
            throw new SourceError(sprintf('%s : aucune feuille dans le classeur.', $label));
        }
        ksort($rows);

        return ['sheet' => $sheet, 'sheets' => $sheets, 'rows' => $rows, 'merges' => $merges];
    }

    /**
     * Lignes et plages fusionnées de la feuille sur laquelle le lecteur est placé. Les lignes sont lues où qu'elles
     * soient rangées (lignes d'en-tête, groupes de lignes) ; une table imbriquée n'est pas une feuille : ignorée.
     *
     * @return array{0: array<int, array<int, string>>, 1: list<array{0: int, 1: int, 2: int, 3: int}>}
     *
     * @throws SourceError
     */
    private static function table(\XMLReader $reader, string $label): array
    {
        $rows = [];
        $merges = [];
        if ($reader->isEmptyElement) {
            return [$rows, $merges];
        }
        $depth = $reader->depth;
        $rowNumber = 0;
        $more = $reader->read();
        while ($more && $reader->depth > $depth) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->namespaceURI !== self::NS_TABLE) {
                $more = $reader->read();

                continue;
            }
            if ($reader->localName === 'table') {
                $more = $reader->next();

                continue;
            }
            if ($reader->localName !== 'table-row') {
                $more = $reader->read();

                continue;
            }
            $repeated = self::count($reader, 'number-rows-repeated');
            [$cells, $spans] = self::row($reader, $label, $rowNumber + 1);
            if ($cells === []) {
                // Ligne vide, même répétée un million de fois (fin de feuille) : la position avance, rien n'est déplié.
                $rowNumber += $repeated;
            } else {
                for ($copy = 0; $copy < $repeated; $copy++) {
                    $rowNumber++;
                    if (\count($rows) >= XlsxReader::MAX_ROWS) {
                        throw new SourceError(sprintf('%s : plus de %d lignes, fichier refusé.', $label, XlsxReader::MAX_ROWS));
                    }
                    $rows[$rowNumber] = $cells;
                    foreach ($spans as [$column, $columns, $lines]) {
                        $merges[] = [$rowNumber, $column, $rowNumber + $lines - 1, $column + $columns - 1];
                    }
                }
            }
            $more = $reader->next();
        }

        return [$rows, $merges];
    }

    /**
     * Cellules remplies de la ligne sur laquelle le lecteur est placé (colonne à partir de 0 => valeur) et étendue
     * des cellules fusionnées qui y commencent (colonne, nombre de colonnes, nombre de lignes).
     *
     * @return array{0: array<int, string>, 1: list<array{0: int, 1: int, 2: int}>}
     *
     * @throws SourceError
     */
    private static function row(\XMLReader $reader, string $label, int $rowNumber): array
    {
        $cells = [];
        $spans = [];
        if ($reader->isEmptyElement) {
            return [$cells, $spans];
        }
        $depth = $reader->depth;
        $column = 0;
        $more = $reader->read();
        while ($more && $reader->depth > $depth) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                $more = $reader->read();

                continue;
            }
            $covered = $reader->namespaceURI === self::NS_TABLE && $reader->localName === 'covered-table-cell';
            if ($covered || ($reader->namespaceURI === self::NS_TABLE && $reader->localName === 'table-cell')) {
                $repeated = self::count($reader, 'number-columns-repeated');
                $columns = self::count($reader, 'number-columns-spanned');
                $lines = self::count($reader, 'number-rows-spanned');
                // Une cellule couverte par une fusion n'a jamais de valeur : celle de la plage est dans sa première cellule.
                $value = $covered ? null : self::cell($reader, $label, $rowNumber);
                if ($value !== null && $value !== '') {
                    if ($column + $repeated > self::MAX_COLUMNS) {
                        throw new SourceError(sprintf('%s, ligne %d : plus de %d colonnes remplies, fichier refusé.', $label, $rowNumber, self::MAX_COLUMNS));
                    }
                    for ($copy = 0; $copy < $repeated; $copy++) {
                        $cells[$column + $copy] = $value;
                    }
                }
                if (!$covered && ($columns > 1 || $lines > 1)) {
                    $spans[] = [$column, $columns, $lines];
                }
                $column += $repeated;
            }
            // Cellule déjà lue en entier, ou élément qui n'est pas une cellule : le lecteur passe au suivant.
            $more = $reader->next();
        }

        return [$cells, $spans];
    }

    /**
     * Valeur de la cellule sur laquelle le lecteur est placé, selon son type ; null si elle est vide.
     * Au retour, le lecteur est encore sur la cellule (son contenu a été parcouru ou non) : l'appelant passe à la
     * suivante avec next().
     *
     * @throws SourceError
     */
    private static function cell(\XMLReader $reader, string $label, int $rowNumber): ?string
    {
        $type = $reader->getAttributeNs('value-type', self::NS_OFFICE);
        if ($reader->getAttributeNs('value-type', self::NS_CALCEXT) === 'error') {
            throw new SourceError(sprintf('%s, ligne %d : cellule en erreur.', $label, $rowNumber));
        }
        switch ($type) {
            case 'float':
            case 'percentage':
            case 'currency':
                $raw = $reader->getAttributeNs('value', self::NS_OFFICE);

                return $raw === null ? self::paragraphs($reader) : XlsxReader::number($raw);
            case 'date':
                $raw = $reader->getAttributeNs('date-value', self::NS_OFFICE);

                return $raw === null ? self::paragraphs($reader) : self::date($raw);
            case 'time':
                return $reader->getAttributeNs('time-value', self::NS_OFFICE) ?? self::paragraphs($reader);
            case 'boolean':
                $raw = $reader->getAttributeNs('boolean-value', self::NS_OFFICE);

                return $raw === null ? self::paragraphs($reader) : ($raw === 'true' ? 'VRAI' : 'FAUX');
            default:
                // Texte (« string »), ou cellule sans type : le texte de ses paragraphes, à défaut la valeur enregistrée.
                $stored = $reader->getAttributeNs('string-value', self::NS_OFFICE);

                return self::paragraphs($reader) ?? $stored;
        }
    }

    /**
     * Date enregistrée (« 2026-09-01 » ou « 2026-09-01T00:00:00 ») : la date seule, sauf si l'heure n'est pas minuit.
     */
    private static function date(string $raw): string
    {
        if (preg_match('/\A(-?[0-9]{4,}-[0-9]{2}-[0-9]{2})(?:T00:00(?::00(?:[.,]0+)?)?)?\z/', $raw, $match) === 1) {
            return $match[1];
        }

        return $raw;
    }

    /**
     * Texte de la cellule sur laquelle le lecteur est placé : ses paragraphes (« text:p », titres « text:h »), réunis
     * par un saut de ligne. Null si la cellule n'a aucun paragraphe. Tout le reste de la cellule (commentaire, objet
     * dessiné, table imbriquée) est sauté.
     */
    private static function paragraphs(\XMLReader $reader): ?string
    {
        if ($reader->isEmptyElement) {
            return null;
        }
        $depth = $reader->depth;
        $paragraphs = [];
        $more = $reader->read();
        while ($more && $reader->depth > $depth) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === $depth + 1
                && $reader->namespaceURI === self::NS_TEXT && ($reader->localName === 'p' || $reader->localName === 'h')) {
                $paragraphs[] = self::paragraph($reader);
            }
            $more = $reader->next();
        }
        // Le lecteur est sur la fin de la cellule : next() de l'appelant mène bien à la cellule suivante.

        return $paragraphs === [] ? null : implode("\n", $paragraphs);
    }

    /**
     * Texte du paragraphe sur lequel le lecteur est placé, morceau par morceau, dans l'ordre du fichier.
     *
     * Règle du format pour les espaces : dans le texte, une suite d'espaces, de tabulations et de retours à la ligne
     * ne vaut qu'un seul espace, et rien en début de paragraphe ; les espaces voulus sont écrits « text:s »
     * (« text:c » en donne le nombre), les tabulations « text:tab », les sauts de ligne « text:line-break ».
     */
    private static function paragraph(\XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $text = '';
        // Vrai tant qu'un espace de mise en page serait de trop : début de paragraphe, ou juste après un autre.
        $collapsed = true;
        $more = $reader->read();
        while ($more && $reader->depth > $depth) {
            switch ($reader->nodeType) {
                case \XMLReader::TEXT:
                case \XMLReader::CDATA:
                case \XMLReader::WHITESPACE:
                case \XMLReader::SIGNIFICANT_WHITESPACE:
                    $piece = (string) preg_replace('/[ \t\r\n]+/', ' ', $reader->value);
                    // Pas d'espace en début de paragraphe, ni deux espaces de mise en page à la suite.
                    $text .= $collapsed ? ltrim($piece, ' ') : $piece;
                    $collapsed = $piece === '' ? $collapsed : str_ends_with($piece, ' ');
                    break;
                case \XMLReader::ELEMENT:
                    if ($reader->namespaceURI !== self::NS_TEXT || \in_array($reader->localName, self::HIDDEN_TEXT, true)) {
                        // Commentaire, note, objet dessiné : pas dans la valeur.
                        $more = $reader->next();

                        continue 2;
                    }
                    if ($reader->localName === 's') {
                        $text .= str_repeat(' ', min(self::count($reader, 'c', self::NS_TEXT), 10_000));
                        $collapsed = false;
                    } elseif ($reader->localName === 'tab') {
                        $text .= "\t";
                        $collapsed = false;
                    } elseif ($reader->localName === 'line-break') {
                        $text .= "\n";
                        $collapsed = true;
                    }
                    // « text:span », « text:a » et les autres : leur texte est lu à la suite.
                    break;
                default:
                    break;
            }
            $more = $reader->read();
        }

        return $text;
    }

    /**
     * Compteur d'une cellule ou d'une ligne (répétition, étendue d'une fusion) : entier d'au moins 1, 1 par défaut.
     */
    private static function count(\XMLReader $reader, string $attribute, string $namespace = self::NS_TABLE): int
    {
        $value = $reader->getAttributeNs($attribute, $namespace);
        if ($value === null || preg_match('/\A[0-9]{1,9}\z/', $value) !== 1) {
            return 1;
        }

        return max(1, (int) $value);
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
        if ($stat['size'] > XlsxReader::MAX_PART_SIZE) {
            throw new SourceError(sprintf('%s : partie « %s » trop volumineuse une fois décompressée.', $label, $name));
        }
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new SourceError(sprintf('%s : partie « %s » illisible.', $label, $name));
        }

        return $content;
    }
}
