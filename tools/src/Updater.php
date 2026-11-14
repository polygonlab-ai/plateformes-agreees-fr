<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Compare les listes lues à la version en place dans data/ et prépare les nouveaux fichiers et le résumé.
 *
 * Rien n'est écrit ici : Updater::run() rend le contenu des fichiers à écrire (vide si la liste n'a pas changé)
 * et le résumé des changements, réutilisé pour le journal des changements et le message d'information.
 *
 * Contrôles qui arrêtent l'actualisation (SourceError, rien n'est écrit) :
 *   - fichier source illisible, colonne inconnue, manquante ou en trop, date illisible, adresse e-mail hors de la
 *     colonne des courriels, nom commercial absent, doublon, liste vide (ListReader) ;
 *   - colonnes différentes de celles de la lecture précédente (data/source.json), sauf option --accepter-colonnes ;
 *   - plateforme présente dans les deux listes à la fois.
 *
 * @phpstan-type Input array{chemin: string, url: string, derniere_modification: string|null}
 * @phpstan-type Event array{date: string, evenement: string, cle: string, statut: string, nom_commercial: string, detail: string}
 * @phpstan-type Section array{titre: string, elements: list<string>}
 */
final class Updater
{
    public const SUMMARY_FORMAT = 1;
    public const REPOSITORY = 'plateformes-agreees-fr';
    public const TIMEZONE = 'Europe/Paris';

    /** @var array<string, string> libellés courts des statuts */
    public const STATUS_LABELS = [
        Platform::STATUS_REGISTERED => 'immatriculée',
        Platform::STATUS_AWAITING_TESTS => 'en attente des tests d\'interopérabilité',
    ];

    public function __construct(
        private readonly Sources $sources,
        private readonly string $dataDirectory,
    ) {}

    /**
     * @param array<string, Input> $inputs liste (clé de tools/sources.json) => fichier lu
     *
     * @return array{changed: bool, files: array<string, string>, summary: array<string, mixed>}
     *
     * @throws SourceError
     */
    public function run(array $inputs, \DateTimeImmutable $now, bool $acceptColumns = false): array
    {
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $previousSource = $this->previousSource();
        $previous = DataFiles::readPlatforms($this->read(DataFiles::PLATFORMS_JSON));
        $firstRead = $previous === [] && ($previousSource['fichiers'] ?? []) === [];

        $platforms = [];
        $sourceFiles = [];
        $lists = [];
        $statedDates = [];
        $modifiedDates = [];
        foreach ($this->sources->files as $file) {
            $key = $file['cle'];
            if (!isset($inputs[$key])) {
                throw new SourceError(sprintf('Fichier de la liste « %s » non fourni (%s).', $key, $file['fichier']));
            }
            $input = $inputs[$key];
            $read = ListReader::read($input['chemin'], $file['fichier'], $key, $file['colonnes']);
            $this->checkColumns($previousSource, $key, $file['fichier'], $read['columns'], $acceptColumns);

            foreach ($read['platforms'] as $platform) {
                if (isset($platforms[$platform->key])) {
                    throw new SourceError(sprintf(
                        '« %s » (clé %s) figure dans les deux listes à la fois : fichier à vérifier.',
                        $platform->name(),
                        $platform->key,
                    ));
                }
                $platforms[$platform->key] = $platform;
            }
            $lists[] = ['cle' => $key, 'libelle' => $file['libelle'], 'nombre' => \count($read['platforms'])];
            if ($read['stated_date'] !== null) {
                $statedDates[] = $read['stated_date'];
            }
            if ($input['derniere_modification'] !== null) {
                $modifiedDates[] = $this->localDate($input['derniere_modification']);
            }
            $hash = hash_file('sha256', $input['chemin']);
            $size = filesize($input['chemin']);
            $sourceFiles[] = [
                'cle' => $key,
                'fichier' => $file['fichier'],
                'url' => $input['url'],
                'sha256' => $hash === false ? '' : $hash,
                'taille' => $size === false ? 0 : $size,
                'derniere_modification' => $input['derniere_modification'],
                'date_indiquee' => $read['stated_date'],
                'feuille' => $read['sheet'],
                'colonnes' => $read['columns'],
                'lignes' => \count($read['platforms']),
            ];
        }

        $readAt = $now->format(DATE_ATOM);
        $sourceDate = $statedDates !== [] ? max($statedDates) : ($modifiedDates !== [] ? max($modifiedDates) : $now->format('Y-m-d'));
        [$events, $others] = $this->compare($previous, $platforms, $now->format('Y-m-d'));
        $changed = $firstRead || $events !== [] || $others !== [];

        $summary = $this->summary($firstRead, $changed, $previous, $platforms, $events, $others, $lists, $sourceDate, $readAt);
        if (!$changed) {
            return ['changed' => false, 'files' => [], 'summary' => $summary];
        }

        $list = array_values($platforms);

        return [
            'changed' => true,
            'files' => [
                DataFiles::PLATFORMS_JSON => DataFiles::platformsJson($this->sources, $sourceDate, $list),
                DataFiles::PLATFORMS_CSV => DataFiles::platformsCsv($list),
                DataFiles::HISTORY_CSV => DataFiles::appendHistory($this->read(DataFiles::HISTORY_CSV), $events),
                DataFiles::SOURCE_JSON => DataFiles::json([
                    'source' => $this->sources->source,
                    'page' => $this->sources->page,
                    'lu_le' => $readAt,
                    'date_source' => $sourceDate,
                    'fichiers' => $sourceFiles,
                ]),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Fichiers vides mais valides, pour un dépôt neuf.
     *
     * @return array<string, string>
     */
    public static function emptyFiles(Sources $sources): array
    {
        return [
            DataFiles::PLATFORMS_JSON => DataFiles::platformsJson($sources, null, []),
            DataFiles::PLATFORMS_CSV => DataFiles::platformsCsv([]),
            DataFiles::HISTORY_CSV => DataFiles::historyHeader(),
            DataFiles::SOURCE_JSON => DataFiles::json([
                'source' => $sources->source,
                'page' => $sources->page,
                'lu_le' => null,
                'date_source' => null,
                'fichiers' => [],
            ]),
        ];
    }

    /**
     * @param array<string, Platform> $previous
     * @param array<string, Platform> $current
     *
     * @return array{0: list<Event>, 1: list<string>} événements de l'historique, autres modifications
     */
    private function compare(array $previous, array $current, string $date): array
    {
        $events = [];
        $others = [];
        ksort($current, SORT_STRING);
        foreach ($current as $key => $platform) {
            $before = $previous[$key] ?? null;
            if ($before === null) {
                $events[] = self::event($date, DataFiles::EVENT_ENTRY, $platform, self::STATUS_LABELS[$platform->status]);
                continue;
            }
            if ($before->status !== $platform->status) {
                $events[] = self::event($date, DataFiles::EVENT_STATUS, $platform, $before->status . ' -> ' . $platform->status);
            }
            // Même clé, nom écrit autrement (majuscules, accents, ponctuation) : changement de nom.
            if ($before->fields['nom_commercial'] !== $platform->fields['nom_commercial']) {
                $events[] = self::event($date, DataFiles::EVENT_NAME, $platform, ($before->fields['nom_commercial'] ?? '(vide)') . ' -> ' . ($platform->fields['nom_commercial'] ?? '(vide)'));
            }
            foreach (Columns::FIELDS as $field) {
                if ($field === 'nom_commercial' || $before->fields[$field] === $platform->fields[$field]) {
                    continue;
                }
                $others[] = sprintf('%s : %s %s -> %s', $platform->label(), $field, $before->fields[$field] ?? '(vide)', $platform->fields[$field] ?? '(vide)');
            }
        }
        $removed = array_diff_key($previous, $current);
        ksort($removed, SORT_STRING);
        foreach ($removed as $platform) {
            $events[] = self::event($date, DataFiles::EVENT_EXIT, $platform, 'retirée de la liste « ' . self::STATUS_LABELS[$platform->status] . ' »');
        }

        return [$events, $others];
    }

    /**
     * @return Event
     */
    private static function event(string $date, string $type, Platform $platform, string $detail): array
    {
        return [
            'date' => $date,
            'evenement' => $type,
            'cle' => $platform->key,
            'statut' => $platform->status,
            'nom_commercial' => $platform->name(),
            'detail' => $detail,
        ];
    }

    /**
     * Résumé des changements : compteurs (garde-fous), sections (journal des changements, message), titre.
     *
     * @param array<string, Platform> $previous
     * @param array<string, Platform> $current
     * @param list<Event> $events
     * @param list<string> $others
     * @param list<array{cle: string, libelle: string, nombre: int}> $lists
     *
     * @return array<string, mixed>
     */
    private function summary(
        bool $firstRead,
        bool $changed,
        array $previous,
        array $current,
        array $events,
        array $others,
        array $lists,
        string $sourceDate,
        string $readAt,
    ): array {
        $byType = array_fill_keys([DataFiles::EVENT_ENTRY, DataFiles::EVENT_EXIT, DataFiles::EVENT_STATUS, DataFiles::EVENT_NAME], []);
        foreach ($events as $event) {
            $platform = $current[$event['cle']] ?? $previous[$event['cle']];
            $line = $platform->label();
            if ($event['evenement'] === DataFiles::EVENT_STATUS) {
                $line .= ' : ' . self::STATUS_LABELS[$previous[$event['cle']]->status] . ' -> ' . self::STATUS_LABELS[$platform->status];
            } elseif ($event['evenement'] === DataFiles::EVENT_NAME) {
                $line .= ' : ' . $event['detail'];
            } elseif ($event['evenement'] === DataFiles::EVENT_ENTRY) {
                $line .= ' (' . self::STATUS_LABELS[$platform->status] . ')';
            }
            $byType[$event['evenement']][] = $line;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $sourceDate);
        $displayDate = $date === false ? $sourceDate : $date->format('d/m/Y');
        $sections = [];
        if ($firstRead) {
            $title = sprintf('Première lecture de la liste du %s : %s', $displayDate, self::plural(\count($current), 'plateforme', 'plateformes'));
        } else {
            $parts = [];
            foreach ([
                DataFiles::EVENT_ENTRY => ['entrée', 'entrées', 'Entrées dans une liste'],
                DataFiles::EVENT_EXIT => ['sortie', 'sorties', 'Sorties'],
                DataFiles::EVENT_STATUS => ['changement de statut', 'changements de statut', 'Changements de statut'],
                DataFiles::EVENT_NAME => ['changement de nom', 'changements de nom', 'Changements de nom'],
            ] as $type => [$singular, $pluralLabel, $sectionTitle]) {
                if ($byType[$type] !== []) {
                    $parts[] = self::plural(\count($byType[$type]), $singular, $pluralLabel);
                    $sections[] = ['titre' => $sectionTitle, 'elements' => $byType[$type]];
                }
            }
            if ($others !== []) {
                $parts[] = self::plural(\count($others), 'autre modification', 'autres modifications');
                $sections[] = ['titre' => 'Autres modifications', 'elements' => $others];
            }
            $title = $changed
                ? sprintf('Liste du %s : %s', $displayDate, implode(', ', $parts))
                : sprintf('Liste du %s : aucun changement', $displayDate);
        }
        $sections[] = [
            'titre' => 'Listes',
            'elements' => array_map(
                static fn(array $list): string => $list['libelle'] . ' : ' . self::plural($list['nombre'], 'plateforme', 'plateformes'),
                $lists,
            ),
        ];

        $text = [$title];
        foreach ($sections as $section) {
            $text[] = '';
            $text[] = $section['titre'];
            foreach ($section['elements'] as $element) {
                $text[] = '- ' . $element;
            }
        }

        return [
            'format' => self::SUMMARY_FORMAT,
            'depot' => self::REPOSITORY,
            'change' => $changed,
            'premiere_lecture' => $firstRead,
            'date_source' => $sourceDate,
            'lu_le' => $readAt,
            'avant' => \count($previous),
            'apres' => \count($current),
            'retraits' => \count($byType[DataFiles::EVENT_EXIT]),
            'listes' => $lists,
            'titre' => $title,
            'sections' => $sections,
            'texte' => implode("\n", $text),
        ];
    }

    /**
     * @param array<string, mixed> $previousSource
     * @param list<string> $columns
     *
     * @throws SourceError
     */
    private function checkColumns(array $previousSource, string $key, string $file, array $columns, bool $acceptColumns): void
    {
        $files = $previousSource['fichiers'] ?? [];
        if (!\is_array($files)) {
            return;
        }
        foreach ($files as $previous) {
            if (!\is_array($previous) || ($previous['cle'] ?? null) !== $key || !\is_array($previous['colonnes'] ?? null)) {
                continue;
            }
            if ($previous['colonnes'] !== $columns && !$acceptColumns) {
                throw new SourceError(sprintf(
                    "%s : colonnes modifiées depuis la dernière lecture.\nAvant : « %s ».\nMaintenant : « %s ».\nVérifier le fichier, puis relancer avec --accepter-colonnes.",
                    $file,
                    implode(' », « ', array_map(static fn(mixed $c): string => \is_string($c) ? $c : '?', $previous['colonnes'])),
                    implode(' », « ', $columns),
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SourceError
     */
    private function previousSource(): array
    {
        $content = $this->read(DataFiles::SOURCE_JSON);

        return $content === '' ? [] : DataFiles::decode($content, DataFiles::SOURCE_JSON);
    }

    private function read(string $name): string
    {
        $path = $this->dataDirectory . '/' . $name;
        if (!is_file($path)) {
            return $name === DataFiles::PLATFORMS_JSON ? '{"plateformes": []}' : '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * @throws SourceError
     */
    private function localDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
        } catch (\Exception) {
            throw new SourceError(sprintf('Date de modification illisible : « %s ».', $date));
        }
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count > 1 ? $plural : $singular);
    }
}
