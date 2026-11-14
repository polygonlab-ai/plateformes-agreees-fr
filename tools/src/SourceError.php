<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Fichier source refusé : structure inattendue, colonne inconnue, donnée invalide, téléchargement impossible.
 *
 * Le message dit ce qui ne va pas, dans quel fichier et à quelle ligne, pour qu'on sache quoi vérifier sans lire le code.
 * Quand cette exception est levée, rien n'est écrit dans data/.
 */
final class SourceError extends \RuntimeException {}
