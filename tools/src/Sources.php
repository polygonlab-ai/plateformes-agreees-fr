<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Fichiers publiés par la DGFiP, déclarés dans tools/sources.json : une entrée par liste.
 *
 * Chaque entrée donne la clé de la liste (qui est aussi le statut des plateformes qu'elle contient), le nom du
 * fichier, son adresse, un libellé et la suite exacte des colonnes attendues dans ce fichier (champs de
 * Columns::ALL_FIELDS) : les deux fichiers n'ont pas les mêmes colonnes (la date de délivrance n'existe que dans la
 * liste des plateformes immatriculées). Ce fichier est lu par tools/actualiser.php et par tout script qui
 * télécharge les listes pour lui.
 *
 * Formats de repli (« replis », facultatif) : la DGFiP publie chaque liste en plusieurs formats. Le fichier principal
 * (XLSX) est toujours essayé le premier ; s'il n'est plus servi (HTTP 404 ou 410), la même liste est lue dans le
 * format de repli suivant (ODS), dans l'ordre de la déclaration. Seuls les formats que l'outil sait lire se
 * déclarent : XLSX et ODS. Le PDF n'en fait pas partie : un tableau ne s'en extrait pas de façon sûre.
 *
 * @phpstan-type Format array{fichier: string, url: string}
 * @phpstan-type File array{cle: string, fichier: string, url: string, libelle: string, colonnes: list<string>, replis: list<Format>}
 */
final class Sources
{
    public const FILE = __DIR__ . '/../sources.json';

    /** Formats lus par l'outil, d'après l'extension du fichier. */
    public const EXTENSIONS = ['xlsx', 'ods'];

    /** Nombre maximal de formats de repli d'une liste. */
    public const MAX_FALLBACKS = 5;

    /**
     * @param list<File> $files
     */
    private function __construct(
        public readonly string $page,
        public readonly string $source,
        public readonly array $files,
    ) {}

    /**
     * @throws SourceError
     */
    public static function load(string $path = self::FILE): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new SourceError('tools/sources.json illisible.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SourceError('tools/sources.json : JSON invalide (' . $error->getMessage() . ').');
        }
        if (!\is_array($data) || !\is_string($data['page'] ?? null) || !\is_string($data['source'] ?? null) || !\is_array($data['fichiers'] ?? null)) {
            throw new SourceError('tools/sources.json : champs « page », « source » et « fichiers » attendus.');
        }
        $files = [];
        foreach ($data['fichiers'] as $entry) {
            if (!\is_array($entry)) {
                throw new SourceError('tools/sources.json : entrée de « fichiers » invalide.');
            }
            $key = $entry['cle'] ?? null;
            $name = $entry['fichier'] ?? null;
            $url = $entry['url'] ?? null;
            $label = $entry['libelle'] ?? null;
            $columns = $entry['colonnes'] ?? null;
            if (!\is_array($columns) || !array_is_list($columns) || $columns === []) {
                throw new SourceError('tools/sources.json : chaque fichier doit donner la liste « colonnes » de ses champs attendus.');
            }
            $fields = [];
            foreach ($columns as $column) {
                if (!\is_string($column) || !\in_array($column, Columns::ALL_FIELDS, true) || \in_array($column, $fields, true)) {
                    throw new SourceError('tools/sources.json : colonne attendue inconnue ou en double (champs admis : ' . implode(', ', Columns::ALL_FIELDS) . ').');
                }
                $fields[] = $column;
            }
            if (!\is_string($key) || !\in_array($key, Platform::STATUSES, true) || !\is_string($name) || !\is_string($label)
                || !\is_string($url) || preg_match('~\Ahttps://[^\s]+\z~', $url) !== 1) {
                throw new SourceError('tools/sources.json : chaque fichier doit avoir une clé (statut connu), un nom, une adresse https et un libellé.');
            }
            if (self::extension($name) === null) {
                throw new SourceError('tools/sources.json : le fichier de la liste « ' . $key . ' » doit être un classeur XLSX ou ODS.');
            }
            $files[] = ['cle' => $key, 'fichier' => $name, 'url' => $url, 'libelle' => $label, 'colonnes' => $fields, 'replis' => self::fallbacks($entry['replis'] ?? [], $key, $url)];
        }
        if (array_column($files, 'cle') !== array_unique(array_column($files, 'cle')) || $files === []) {
            throw new SourceError('tools/sources.json : une entrée par liste, sans doublon.');
        }
        $names = [];
        foreach ($files as $file) {
            $names = [...$names, $file['fichier'], ...array_column($file['replis'], 'fichier')];
        }
        if ($names !== array_values(array_unique($names))) {
            throw new SourceError('tools/sources.json : un même nom de fichier ne peut être déclaré qu\'une fois (fichiers et formats de repli).');
        }

        return new self($data['page'], $data['source'], $files);
    }

    /**
     * @return File
     *
     * @throws SourceError
     */
    public function file(string $key): array
    {
        foreach ($this->files as $file) {
            if ($file['cle'] === $key) {
                return $file;
            }
        }

        throw new SourceError(sprintf('Liste « %s » inconnue de tools/sources.json.', $key));
    }

    /**
     * Formats déclarés pour une liste, dans l'ordre où ils s'essaient : le fichier principal, puis ses replis.
     *
     * @return non-empty-list<Format>
     *
     * @throws SourceError
     */
    public function formats(string $key): array
    {
        $file = $this->file($key);

        return [['fichier' => $file['fichier'], 'url' => $file['url']], ...$file['replis']];
    }

    /**
     * Format déclaré auquel correspond un fichier réellement lu : celui de même adresse si elle est connue, sinon
     * celui de même extension que le fichier lu, sinon le fichier principal. C'est ce nom et cette adresse qui vont
     * dans data/source.json : ils disent quel fichier officiel a été lu, pas seulement lequel était attendu.
     *
     * @return Format
     *
     * @throws SourceError
     */
    public function format(string $key, string $path, ?string $url = null): array
    {
        $formats = $this->formats($key);
        foreach ($formats as $format) {
            if ($url !== null && $format['url'] === $url) {
                return $format;
            }
        }
        $extension = self::extension($path);
        foreach ($formats as $format) {
            if ($extension !== null && self::extension($format['fichier']) === $extension) {
                return $format;
            }
        }

        return $formats[0];
    }

    /**
     * Extension d'un fichier, en minuscules, si l'outil sait lire ce format (« xlsx » ou « ods ») ; sinon null.
     */
    public static function extension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return \in_array($extension, self::EXTENSIONS, true) ? $extension : null;
    }

    /**
     * Formats de repli d'une liste : liste ordonnée d'objets « fichier » (nom simple, XLSX ou ODS) et « url » (https).
     *
     * @return list<Format>
     *
     * @throws SourceError
     */
    private static function fallbacks(mixed $declared, string $key, string $mainUrl): array
    {
        $refusal = sprintf(
            'tools/sources.json : « replis » de la liste « %s » doit être une liste de %d formats au plus, chacun avec un fichier XLSX ou ODS (nom simple) et une adresse https citée une seule fois.',
            $key,
            self::MAX_FALLBACKS,
        );
        if (!\is_array($declared) || !array_is_list($declared) || \count($declared) > self::MAX_FALLBACKS) {
            throw new SourceError($refusal);
        }
        $fallbacks = [];
        $urls = [$mainUrl];
        foreach ($declared as $entry) {
            $name = \is_array($entry) ? ($entry['fichier'] ?? null) : null;
            $url = \is_array($entry) ? ($entry['url'] ?? null) : null;
            if (!\is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $name) !== 1 || self::extension($name) === null
                || !\is_string($url) || preg_match('~\Ahttps://[^\s]+\z~', $url) !== 1 || \in_array($url, $urls, true)) {
                throw new SourceError($refusal);
            }
            $urls[] = $url;
            $fallbacks[] = ['fichier' => $name, 'url' => $url];
        }

        return $fallbacks;
    }
}
