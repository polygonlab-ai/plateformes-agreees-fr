<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Tests\Fixtures;

use PolygonLab\PlateformesAgreees\Outils\Platform;
use PolygonLab\PlateformesAgreees\Outils\Sources;
use PolygonLab\PlateformesAgreees\Outils\Updater;

/**
 * Dossier de travail temporaire d'un test : dossier data/ vide mais valide, classeurs d'essai, effacement.
 */
final class Workspace
{
    public readonly string $path;

    public readonly string $data;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/plateformes-essai-' . bin2hex(random_bytes(6));
        $this->data = $this->path . '/data';
        mkdir($this->data, 0700, true);
        foreach (Updater::emptyFiles(Sources::load()) as $name => $content) {
            file_put_contents($this->data . '/' . $name, $content);
        }
    }

    /**
     * Écrit les deux classeurs d'essai (structure de la DGFiP) et rend les entrées attendues par Updater::run().
     *
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $registered
     *        plateformes immatriculées (avec date de délivrance)
     * @param list<array{0: string|null, 1?: string|int|null, 2?: string|int|null, 3?: string|null, 4?: string|null, 5?: string|null, 6?: string|int|null}> $awaiting
     *        plateformes en attente des tests (sans colonne de date)
     *
     * @return array<string, array{chemin: string, url: string, derniere_modification: string|null}>
     */
    public function inputs(array $registered, array $awaiting, ?string $modified = '2026-11-12T09:30:00+00:00'): array
    {
        $sources = Sources::load();
        $first = $sources->file(Platform::STATUS_REGISTERED);
        $second = $sources->file(Platform::STATUS_AWAITING_TESTS);

        return $this->files(
            XlsxBuilder::file($this->path . '/' . $first['fichier'], $registered),
            XlsxBuilder::file($this->path . '/' . $second['fichier'], $awaiting, false),
            $modified,
        );
    }

    /**
     * Entrées attendues par Updater::run() pour deux classeurs déjà écrits, au format XLSX ou ODS : l'adresse est
     * celle du format fourni (fichier principal, ou format de repli de même extension).
     *
     * @return array<string, array{chemin: string, url: string, derniere_modification: string|null}>
     */
    public function files(string $registered, string $awaiting, ?string $modified = '2026-11-12T09:30:00+00:00'): array
    {
        $sources = Sources::load();
        $inputs = [];
        foreach ([Platform::STATUS_REGISTERED => $registered, Platform::STATUS_AWAITING_TESTS => $awaiting] as $key => $path) {
            $inputs[$key] = ['chemin' => $path, 'url' => $sources->format($key, $path)['url'], 'derniere_modification' => $modified];
        }

        return $inputs;
    }

    /**
     * @param array<string, string> $files
     */
    public function store(array $files): void
    {
        foreach ($files as $name => $content) {
            file_put_contents($this->data . '/' . $name, $content);
        }
    }

    public function read(string $name): string
    {
        return (string) file_get_contents($this->data . '/' . $name);
    }

    public function remove(): void
    {
        self::delete($this->path);
    }

    private static function delete(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
