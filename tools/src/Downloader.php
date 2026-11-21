<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Téléchargement des fichiers de la DGFiP (extension PHP curl), pour un usage autonome de tools/actualiser.php.
 *
 * Politesse et prudence : en-tête User-Agent qui identifie l'outil, trois essais espacés au plus, délai borné,
 * taille bornée, adresses https uniquement (redirections comprises), réponse 200 exigée.
 *
 * Formats de repli : quand le fichier principal d'une liste n'est plus servi (HTTP 404 ou 410), la même liste est
 * téléchargée dans le format suivant déclaré dans tools/sources.json (Downloader::first()). Une panne du site
 * (erreur du serveur, délai dépassé) ne déclenche pas de repli : le téléchargement échoue, comme avant.
 */
final class Downloader
{
    public const USER_AGENT = 'plateformes-agreees-fr/1.0 (+https://github.com/polygonlab-ai/plateformes-agreees-fr)';
    public const ATTEMPTS = 3;
    public const TIMEOUT = 60;
    public const MAX_SIZE = 20_000_000;

    /** Codes HTTP d'un fichier retiré ou déplacé : le format de repli suivant est essayé. */
    public const MISSING = [404, 410];

    /** Pause avant le 2e puis le 3e essai (secondes). */
    private const PAUSES = [5, 15];

    /**
     * Télécharge le fichier dans $destination ; rend la date de modification annoncée par le serveur (ISO 8601) ou null.
     *
     * @throws SourceError
     */
    public static function download(string $url, string $destination): ?string
    {
        [$ok, $modified, $last] = self::attempts($url, $destination);
        if (!$ok) {
            throw new SourceError(self::failure($url, $last));
        }

        return $modified;
    }

    /**
     * Télécharge une liste dans le premier de ses formats que le site sert encore : le fichier principal, puis chaque
     * format de repli, seulement si le précédent est introuvable (HTTP 404 ou 410). Le fichier est écrit dans
     * $directory sous son nom déclaré.
     *
     * @param non-empty-list<array{fichier: string, url: string}> $formats formats de la liste, dans l'ordre où ils s'essaient
     *
     * @return array{fichier: string, url: string, chemin: string, derniere_modification: string|null, introuvables: list<string>}
     *         format téléchargé, et noms des formats introuvables essayés avant lui
     *
     * @throws SourceError
     */
    public static function first(array $formats, string $directory): array
    {
        $missing = [];
        $failures = [];
        foreach ($formats as $format) {
            $path = $directory . '/' . basename($format['fichier']);
            [$ok, $modified, $last, $status] = self::attempts($format['url'], $path);
            if ($ok) {
                return ['fichier' => $format['fichier'], 'url' => $format['url'], 'chemin' => $path, 'derniere_modification' => $modified, 'introuvables' => $missing];
            }
            $failures[] = self::failure($format['url'], $last);
            if (!self::missing($status)) {
                break;
            }
            $missing[] = $format['fichier'];
        }

        throw new SourceError(implode("\n", $failures));
    }

    /**
     * Vrai pour le code HTTP d'un fichier retiré ou déplacé (404, 410) : seul cas où le format suivant est essayé.
     */
    public static function missing(int $status): bool
    {
        return \in_array($status, self::MISSING, true);
    }

    private static function failure(string $url, string $last): string
    {
        return sprintf('Téléchargement impossible après %d essais : %s (%s).', self::ATTEMPTS, $url, $last);
    }

    /**
     * Les essais d'un téléchargement : réussite, date de modification annoncée, dernière erreur, dernier code HTTP.
     *
     * @return array{0: bool, 1: string|null, 2: string, 3: int}
     *
     * @throws SourceError
     */
    private static function attempts(string $url, string $destination): array
    {
        if (!\function_exists('curl_init')) {
            throw new SourceError('Extension PHP curl absente : téléchargement impossible (fournir les fichiers avec --source).');
        }
        $last = '';
        $status = 0;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep(self::PAUSES[$attempt - 2]);
            }
            [$ok, $modified, $last, $status] = self::attempt($url, $destination);
            if ($ok) {
                return [true, $modified, '', $status];
            }
        }

        return [false, null, $last, $status];
    }

    /**
     * @return array{0: bool, 1: string|null, 2: string, 3: int}
     */
    private static function attempt(string $url, string $destination): array
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new SourceError('Écriture impossible : ' . $destination . '.');
        }
        $modified = null;
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);

            return [false, null, 'initialisation de curl impossible', 0];
        }
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FILETIME => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn(mixed $c, int $total, int $received): int => $received > self::MAX_SIZE ? 1 : 0,
        ]);
        $done = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $time = curl_getinfo($curl, CURLINFO_FILETIME);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);
        if ($done === false || $status !== 200) {
            return [false, null, $error !== '' ? $error : 'HTTP ' . $status, $status];
        }
        if ($time > 0) {
            $modified = gmdate(DATE_ATOM, $time);
        }

        return [true, $modified, '', $status];
    }
}
