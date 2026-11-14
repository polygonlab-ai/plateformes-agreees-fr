<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Nettoyage des textes lus dans les fichiers : espaces insécables et retours à la ligne remplacés, espaces réduits.
 */
final class Text
{
    /**
     * Texte nettoyé, ou null s'il est vide.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}\s]+/u', ' ', $value);
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Intitulé de colonne lisible : mot coupé par un trait d'union en fin de ligne recollé (« établis-\nsement »
     * donne « établissement »), puis texte nettoyé. Null s'il est vide.
     */
    public static function header(?string $value): ?string
    {
        return $value === null ? null : self::clean((string) preg_replace('/-[ \t]*\R[ \t]*/u', '', $value));
    }

    /**
     * Forme stable d'un nom pour fabriquer une clé : minuscules sans accent, mots séparés par des tirets.
     */
    public static function slug(string $value): string
    {
        $slug = Columns::normalize($value);

        return str_replace(' ', '-', $slug);
    }
}
