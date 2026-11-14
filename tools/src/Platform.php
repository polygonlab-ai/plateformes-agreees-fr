<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Une plateforme agréée, telle qu'elle figure dans une des deux listes de la DGFiP.
 *
 * Les fichiers de la DGFiP ne donnent ni SIREN ni raison sociale : seul le nom commercial identifie la plateforme.
 * La clé, stable d'une lecture à l'autre, est donc « nom:<nom commercial simplifié> » (minuscules sans accent, mots
 * séparés par des tirets). Aucun SIREN n'est déduit : ce serait deviner (décision du 14/11/2026).
 */
final class Platform
{
    public const STATUS_REGISTERED = 'immatriculee';
    public const STATUS_AWAITING_TESTS = 'attente_tests_interoperabilite';

    public const STATUSES = [self::STATUS_REGISTERED, self::STATUS_AWAITING_TESTS];

    /**
     * @param array<string, string|null> $fields champs de Columns::FIELDS (jamais le courriel)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly array $fields,
    ) {}

    /**
     * @param array<string, string|null> $fields champs lus ; ceux qui ne sont pas dans Columns::FIELDS sont écartés
     */
    public static function create(string $status, array $fields): self
    {
        $published = [];
        foreach (Columns::FIELDS as $field) {
            $published[$field] = $fields[$field] ?? null;
        }
        $published['immatriculation_en_attente'] = $published['immatriculation_en_attente'] === Columns::YES ? Columns::YES : Columns::NO;

        return new self(self::key($published['nom_commercial'] ?? ''), $status, $published);
    }

    /**
     * Clé d'un nom commercial : « @GP » donne « nom:gp ». Chaîne vide si le nom ne contient ni lettre ni chiffre.
     */
    public static function key(string $name): string
    {
        $slug = Text::slug($name);

        return $slug === '' ? '' : 'nom:' . $slug;
    }

    public function name(): string
    {
        return $this->fields['nom_commercial'] ?? '';
    }

    /**
     * Libellé lisible : « Nom commercial (Commune ou pays) ».
     */
    public function label(): string
    {
        $place = $this->fields['adresse_commune_ou_pays'];

        return $this->name() . ($place !== null ? ' (' . $place . ')' : '');
    }

    /**
     * Enregistrement écrit dans data/plateformes.json, clés dans un ordre fixe.
     *
     * @return array{cle: string, nom_commercial: string|null, statut: string, adresse: array{voie: string|null, code_postal: string|null, commune_ou_pays: string|null, pays: string|null}, site_internet: string|null, date_immatriculation: string|null, immatriculation_en_attente: bool}
     */
    public function toArray(): array
    {
        return [
            'cle' => $this->key,
            'nom_commercial' => $this->fields['nom_commercial'],
            'statut' => $this->status,
            'adresse' => [
                'voie' => $this->fields['adresse_voie'],
                'code_postal' => $this->fields['adresse_code_postal'],
                'commune_ou_pays' => $this->fields['adresse_commune_ou_pays'],
                'pays' => $this->fields['adresse_pays'],
            ],
            'site_internet' => $this->fields['site_internet'],
            'date_immatriculation' => $this->fields['date_immatriculation'],
            'immatriculation_en_attente' => $this->fields['immatriculation_en_attente'] === Columns::YES,
        ];
    }

    /**
     * Ligne de data/plateformes.csv, dans l'ordre de DataFiles::CSV_COLUMNS.
     *
     * @return list<string|null>
     */
    public function toCsv(): array
    {
        return [
            $this->key,
            $this->fields['nom_commercial'],
            $this->status,
            $this->fields['adresse_voie'],
            $this->fields['adresse_code_postal'],
            $this->fields['adresse_commune_ou_pays'],
            $this->fields['adresse_pays'],
            $this->fields['site_internet'],
            $this->fields['date_immatriculation'],
            $this->fields['immatriculation_en_attente'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws SourceError
     */
    public static function fromArray(array $record): self
    {
        $key = $record['cle'] ?? null;
        $status = $record['statut'] ?? null;
        if (!\is_string($key) || !\is_string($status) || !\in_array($status, self::STATUSES, true)) {
            throw new SourceError('data/plateformes.json : enregistrement sans clé ou sans statut valide.');
        }
        $address = \is_array($record['adresse'] ?? null) ? $record['adresse'] : [];
        $values = [
            'nom_commercial' => $record['nom_commercial'] ?? null,
            'adresse_voie' => $address['voie'] ?? null,
            'adresse_code_postal' => $address['code_postal'] ?? null,
            'adresse_commune_ou_pays' => $address['commune_ou_pays'] ?? null,
            'adresse_pays' => $address['pays'] ?? null,
            'site_internet' => $record['site_internet'] ?? null,
            'date_immatriculation' => $record['date_immatriculation'] ?? null,
            'immatriculation_en_attente' => ($record['immatriculation_en_attente'] ?? false) === true ? Columns::YES : Columns::NO,
        ];
        $fields = [];
        foreach ($values as $field => $value) {
            $fields[$field] = \is_string($value) ? $value : null;
        }

        return new self($key, $status, $fields);
    }
}
