<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Colonnes des fichiers de la DGFiP et champ qui reçoit chacune.
 *
 * Les fichiers ont un en-tête sur deux lignes : la ligne 1 porte l'intitulé de la colonne, la ligne 2 précise les
 * sous-colonnes d'un intitulé fusionné (« Adresse de l'établissement principal » sur trois colonnes : « Voie »,
 * « Code postal », « Commune (Pays le cas échéant) »). L'intitulé complet d'une colonne est donc « parent > enfant »
 * quand la ligne 2 est remplie, et l'intitulé de la ligne 1 seul sinon.
 *
 * Les colonnes ne sont jamais devinées par leur position : chaque intitulé complet est reconnu grâce à la table
 * ci-dessous, après normalisation (minuscules, sans accent, apostrophes unifiées, mot coupé en fin de ligne recollé,
 * ponctuation et espaces réduits à un espace). Un intitulé absent de la table arrête la lecture avec la liste des
 * intitulés lus : il faut alors vérifier le fichier, puis l'ajouter ici, jamais l'ignorer en silence.
 */
final class Columns
{
    /** Séparateur entre l'intitulé fusionné de la ligne 1 et la sous-colonne de la ligne 2. */
    public const SEPARATOR = ' > ';

    /** Intitulé qui repère la ligne d'en-tête. */
    public const MARKER = 'nom_commercial';

    /** Champ lu pour contrôler la structure, jamais écrit dans data/ (décision du 14/11/2026). */
    public const NEVER_PUBLISHED = 'courriel';

    /**
     * Intitulé complet normalisé => champ.
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        'nom commercial' => 'nom_commercial',
        'adresse de l etablissement principal > voie' => 'adresse_voie',
        'adresse de l etablissement principal > code postal' => 'adresse_code_postal',
        'adresse de l etablissement principal > commune pays le cas echeant' => 'adresse_commune_ou_pays',
        'site internet' => 'site_internet',
        'courriel de contact' => 'courriel',
        'date de delivrance du numero d immatriculation' => 'date_immatriculation',
    ];

    /**
     * Champs écrits dans data/, dans l'ordre des fichiers CSV. Deux champs ne sont pas des colonnes :
     *   - « adresse_pays » est déduit de la colonne « Commune (Pays le cas échéant) » par la liste fermée des pays
     *     (Countries) ;
     *   - « immatriculation_en_attente » vaut « oui » quand la DGFiP écrit « En attente » à la place de la date de
     *     délivrance (date pas encore connue), « non » sinon (rendu en booléen dans data/plateformes.json).
     */
    public const FIELDS = ['nom_commercial', 'adresse_voie', 'adresse_code_postal', 'adresse_commune_ou_pays', 'adresse_pays', 'site_internet', 'date_immatriculation', 'immatriculation_en_attente'];

    /** Seul texte accepté à la place d'une date de délivrance, comparé après normalisation. */
    public const DATE_PENDING = 'En attente';

    /** Valeurs du champ « immatriculation_en_attente » dans les fichiers CSV. */
    public const YES = 'oui';
    public const NO = 'non';

    /** Tous les champs reconnus, y compris ceux qui ne sont jamais publiés. */
    public const ALL_FIELDS = ['nom_commercial', 'adresse_voie', 'adresse_code_postal', 'adresse_commune_ou_pays', 'site_internet', 'courriel', 'date_immatriculation'];

    /**
     * Champ d'un intitulé complet (« parent » ou « parent > enfant »), ou null s'il n'est pas reconnu.
     */
    public static function field(string $parent, ?string $child = null): ?string
    {
        return self::KNOWN[self::label($parent, $child)] ?? null;
    }

    /**
     * Intitulé complet normalisé : « Adresse\nde l'établis-\nsement principal » et « Voie » donnent
     * « adresse de l etablissement principal > voie ».
     */
    public static function label(string $parent, ?string $child = null): string
    {
        $label = self::normalize($parent);
        $child = $child === null ? '' : self::normalize($child);

        return $child === '' ? $label : $label . self::SEPARATOR . $child;
    }

    /**
     * Forme normalisée d'un intitulé : « Date de délivrance\ndu numéro d’immatriculation » donne
     * « date de delivrance du numero d immatriculation ».
     */
    public static function normalize(string $header): string
    {
        // Mot coupé par un trait d'union en fin de ligne (« établis-\nsement ») : recollé.
        $text = (string) preg_replace('/-[ \t]*\R[ \t]*/u', '', $header);
        $text = strtr($text, ["\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'", "\u{00B4}" => "'", '`' => "'"]);
        $text = mb_strtolower(Text::clean($text) ?? '', 'UTF-8');
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe',
            'æ' => 'ae', '°' => ' ', 'º' => ' ',
        ]);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }

    /**
     * Intitulés reconnus, pour les messages d'erreur.
     */
    public static function describe(): string
    {
        $lines = [];
        foreach (self::KNOWN as $label => $field) {
            $lines[] = '« ' . $label . ' » (' . $field . ')';
        }

        return implode(', ', $lines);
    }
}
