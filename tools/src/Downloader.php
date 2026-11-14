<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Téléchargement des fichiers de la DGFiP (extension PHP curl), pour un usage autonome de tools/actualiser.php.
 *
 * Politesse et prudence : en-tête User-Agent qui identifie l'outil, trois essais espacés au plus, délai borné,
 * taille bornée, adresses https uniquement (redirections comprises), réponse 200 exigée.
 */
final class Downloader
{
    public const USER_AGENT = 'plateformes-agreees-fr/1.0 (+https://github.com/polygonlab-ai/plateformes-agreees-fr)';
    public const ATTEMPTS = 3;
    public const TIMEOUT = 60;
    public const MAX_SIZE = 20_000_000;

    /** Pause avant le 2e puis le 3e essai (secondes). */
    private const PAUSES = [5, 15];

    /**
     * Télécharge le fichier dans $destination ; rend la date de modification annoncée par le serveur (ISO 8601) ou null.
     *
     * @throws SourceError
     */
    public static function download(string $url, string $destination): ?string
    {
        if (!\function_exists('curl_init')) {
            throw new SourceError('Extension PHP curl absente : téléchargement impossible (fournir les fichiers avec --source).');
        }
        $last = '';
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep(self::PAUSES[$attempt - 2] ?? 15);
            }
            [$ok, $modified, $last] = self::attempt($url, $destination);
            if ($ok) {
                return $modified;
            }
        }

        throw new SourceError(sprintf('Téléchargement impossible après %d essais : %s (%s).', self::ATTEMPTS, $url, $last));
    }

    /**
     * @return array{0: bool, 1: string|null, 2: string}
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

            return [false, null, 'initialisation de curl impossible'];
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
            return [false, null, $error !== '' ? $error : 'HTTP ' . $status];
        }
        if (\is_int($time) && $time > 0) {
            $modified = gmdate(DATE_ATOM, $time);
        }

        return [true, $modified, ''];
    }
}
