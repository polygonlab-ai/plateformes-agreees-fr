<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Lecture et écriture des fichiers du dossier data/.
 *
 *   plateformes.json   liste courante, clés dans un ordre fixe, plateformes triées par clé ; jamais de courriel
 *   plateformes.csv    même contenu, séparateur « ; », UTF-8 avec BOM (ouverture directe dans Excel)
 *   historique.csv     un événement par ligne (entrée, sortie, changement de statut, changement de nom) ; ne fait
 *                      que s'allonger
 *   source.json        adresse, empreinte SHA-256, date annoncée par le serveur et colonnes de chaque fichier lu
 *
 * Le rendu est déterministe : mêmes données, mêmes octets.
 */
final class DataFiles
{
    public const PLATFORMS_JSON = 'plateformes.json';
    public const PLATFORMS_CSV = 'plateformes.csv';
    public const HISTORY_CSV = 'historique.csv';
    public const SOURCE_JSON = 'source.json';

    public const BOM = "\u{FEFF}";
    public const SEPARATOR = ';';

    public const HISTORY_COLUMNS = ['date', 'evenement', 'cle', 'statut', 'nom_commercial', 'detail'];

    public const CSV_COLUMNS = ['cle', 'nom_commercial', 'statut', 'adresse_voie', 'adresse_code_postal', 'adresse_commune_ou_pays', 'adresse_pays', 'site_internet', 'date_immatriculation', 'immatriculation_en_attente'];

    public const EVENT_ENTRY = 'entree';
    public const EVENT_EXIT = 'sortie';
    public const EVENT_STATUS = 'changement_statut';
    public const EVENT_NAME = 'changement_nom';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param list<Platform> $platforms
     */
    public static function platformsJson(Sources $sources, ?string $sourceDate, array $platforms): string
    {
        $counts = array_fill_keys(Platform::STATUSES, 0);
        foreach ($platforms as $platform) {
            $counts[$platform->status]++;
        }

        return self::json([
            'source' => $sources->source,
            'page' => $sources->page,
            'date_source' => $sourceDate,
            'nombre' => \count($platforms),
            'nombres' => $counts,
            'plateformes' => array_map(static fn(Platform $platform): array => $platform->toArray(), self::sorted($platforms)),
        ]);
    }

    /**
     * @param list<Platform> $platforms
     */
    public static function platformsCsv(array $platforms): string
    {
        $lines = [self::csvLine(self::CSV_COLUMNS)];
        foreach (self::sorted($platforms) as $platform) {
            $lines[] = self::csvLine($platform->toCsv());
        }

        return self::BOM . implode("\n", $lines) . "\n";
    }

    /**
     * En-tête seul de l'historique (fichier vide).
     *
     * @return non-empty-string
     */
    public static function historyHeader(): string
    {
        return self::BOM . self::csvLine(self::HISTORY_COLUMNS) . "\n";
    }

    /**
     * Historique allongé des nouveaux événements.
     *
     * @param list<array{date: string, evenement: string, cle: string, statut: string, nom_commercial: string, detail: string}> $events
     */
    public static function appendHistory(string $current, array $events): string
    {
        if ($current === '') {
            $current = self::historyHeader();
        }
        if (!str_starts_with($current, self::BOM . self::csvLine(self::HISTORY_COLUMNS) . "\n")) {
            throw new SourceError('data/historique.csv : en-tête inattendu, fichier non modifié.');
        }
        $lines = '';
        foreach ($events as $event) {
            $lines .= self::csvLine(array_values($event)) . "\n";
        }

        return $current . $lines;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, self::JSON_FLAGS) . "\n";
    }

    /**
     * Plateformes de data/plateformes.json (liste vide si le fichier n'en contient aucune).
     *
     * @return array<string, Platform> clé => plateforme
     *
     * @throws SourceError
     */
    public static function readPlatforms(string $content): array
    {
        $data = self::decode($content, self::PLATFORMS_JSON);
        $list = $data['plateformes'] ?? null;
        if (!\is_array($list)) {
            throw new SourceError('data/plateformes.json : liste « plateformes » absente.');
        }
        $platforms = [];
        foreach ($list as $record) {
            if (!\is_array($record)) {
                throw new SourceError('data/plateformes.json : enregistrement invalide.');
            }
            /** @var array<string, mixed> $record */
            $platform = Platform::fromArray($record);
            $platforms[$platform->key] = $platform;
        }

        return $platforms;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SourceError
     */
    public static function decode(string $content, string $name): array
    {
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SourceError('data/' . $name . ' : JSON invalide (' . $error->getMessage() . ').');
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw new SourceError('data/' . $name . ' : objet JSON attendu.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Ligne CSV : valeur entre guillemets si elle contient le séparateur, un guillemet ou un retour à la ligne.
     *
     * @param list<string|null> $values
     */
    public static function csvLine(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $value ??= '';
            if (strpbrk($value, self::SEPARATOR . "\"\n\r") !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $cells[] = $value;
        }

        return implode(self::SEPARATOR, $cells);
    }

    /**
     * @param list<Platform> $platforms
     *
     * @return list<Platform>
     */
    public static function sorted(array $platforms): array
    {
        usort($platforms, static fn(Platform $a, Platform $b): int => strcmp($a->key, $b->key));

        return $platforms;
    }
}
