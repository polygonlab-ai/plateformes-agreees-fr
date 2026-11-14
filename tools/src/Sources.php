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
 */
final class Sources
{
    public const FILE = __DIR__ . '/../sources.json';

    /**
     * @param list<array{cle: string, fichier: string, url: string, libelle: string, colonnes: list<string>}> $files
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
            $files[] = ['cle' => $key, 'fichier' => $name, 'url' => $url, 'libelle' => $label, 'colonnes' => $fields];
        }
        if (array_column($files, 'cle') !== array_unique(array_column($files, 'cle')) || $files === []) {
            throw new SourceError('tools/sources.json : une entrée par liste, sans doublon.');
        }

        return new self($data['page'], $data['source'], $files);
    }

    /**
     * @return array{cle: string, fichier: string, url: string, libelle: string, colonnes: list<string>}
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
}
