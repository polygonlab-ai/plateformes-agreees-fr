<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Commande « php tools/actualiser.php » : arguments, lecture, écriture des fichiers de data/, résumé.
 *
 * Codes de sortie : 0 succès (fichiers réécrits, ou liste inchangée) ; 1 source refusée, rien n'est écrit ;
 * 2 usage.
 */
final class Command
{
    public const SUCCESS = 0;
    public const REFUSED = 1;
    public const USAGE = 2;

    /**
     * @param list<string> $arguments arguments de la ligne de commande, sans le nom du script
     * @param resource $output
     * @param resource $errors
     */
    public static function run(array $arguments, $output, $errors, ?\DateTimeImmutable $now = null): int
    {
        try {
            $options = self::options($arguments);
        } catch (\InvalidArgumentException $error) {
            fwrite($errors, $error->getMessage() . "\n\n" . self::usage());

            return self::USAGE;
        }
        if ($options['aide']) {
            fwrite($output, self::usage());

            return self::SUCCESS;
        }

        $temporary = null;
        try {
            $sources = Sources::load();
            $inputs = [];
            if ($options['manifeste'] !== null) {
                $inputs = self::manifest($options['manifeste']);
            }
            foreach ($options['sources'] as $key => $path) {
                $inputs[$key] = ['chemin' => $path, 'url' => $sources->file($key)['url'], 'derniere_modification' => null];
            }
            if ($inputs === []) {
                $temporary = sys_get_temp_dir() . '/plateformes-agreees-' . bin2hex(random_bytes(6));
                if (!mkdir($temporary, 0700)) {
                    throw new SourceError('Dossier temporaire impossible à créer.');
                }
                foreach ($sources->files as $file) {
                    fwrite($output, 'Téléchargement : ' . $file['url'] . "\n");
                    $path = $temporary . '/' . basename($file['fichier']);
                    $inputs[$file['cle']] = ['chemin' => $path, 'url' => $file['url'], 'derniere_modification' => Downloader::download($file['url'], $path)];
                }
            }

            $result = (new Updater($sources, $options['donnees']))->run($inputs, $now ?? new \DateTimeImmutable('now'), $options['accepter-colonnes']);
            if ($result['changed']) {
                self::write($options['donnees'], $result['files']);
            }
            if ($options['resume'] !== null && file_put_contents($options['resume'], DataFiles::json($result['summary'])) === false) {
                throw new SourceError('Écriture du résumé impossible : ' . $options['resume'] . '.');
            }
            $text = $result['summary']['texte'];
            fwrite($output, (\is_string($text) ? $text : '') . "\n\n" . ($result['changed'] ? 'Fichiers de data/ réécrits.' : 'Liste inchangée : aucun fichier modifié.') . "\n");

            return self::SUCCESS;
        } catch (SourceError $error) {
            fwrite($errors, 'Arrêt : ' . $error->getMessage() . "\nAucun fichier n'a été écrit.\n");

            return self::REFUSED;
        } finally {
            if ($temporary !== null) {
                foreach (glob($temporary . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($temporary);
            }
        }
    }

    public static function usage(): string
    {
        return <<<'TXT'
            Usage : php tools/actualiser.php [options]

            Sans option, télécharge les deux listes de la DGFiP (tools/sources.json), les compare à data/ et réécrit
            les fichiers si la liste a changé.

              --source <liste>=<fichier.xlsx>   fichier déjà téléchargé (listes : immatriculee,
                                                attente_tests_interoperabilite) ; option répétable
              --manifeste <fichier.json>        fichiers déjà téléchargés, avec leur date de modification
              --donnees <dossier>               dossier des données (défaut : data/ du dépôt)
              --resume <fichier.json>           écrit le résumé des changements en JSON
              --accepter-colonnes               accepte des colonnes différentes de la lecture précédente
              --chargeur <fichier.php>          chargeur automatique facultatif (défaut : vendor/autoload.php s'il existe)
              --aide                            affiche cette aide

            Codes de sortie : 0 succès, 1 source refusée (rien n'est écrit), 2 usage.

            TXT;
    }

    /**
     * Chargeur automatique désigné par --chargeur (lu avant toute autre option), sinon null.
     *
     * @param list<string> $arguments
     */
    public static function loader(array $arguments): ?string
    {
        foreach ($arguments as $index => $argument) {
            if ($argument === '--chargeur') {
                return $arguments[$index + 1] ?? null;
            }
            if (str_starts_with($argument, '--chargeur=')) {
                return substr($argument, \strlen('--chargeur='));
            }
        }

        return null;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{sources: array<string, string>, manifeste: string|null, donnees: string, resume: string|null, accepter-colonnes: bool, aide: bool}
     */
    private static function options(array $arguments): array
    {
        $options = [
            'sources' => [],
            'manifeste' => null,
            'donnees' => \dirname(__DIR__, 2) . '/data',
            'resume' => null,
            'accepter-colonnes' => false,
            'aide' => false,
        ];
        $withValue = ['--source', '--manifeste', '--donnees', '--resume', '--chargeur'];
        for ($i = 0, $count = \count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];
            $value = null;
            if (str_contains($argument, '=') && str_starts_with($argument, '--')) {
                [$argument, $value] = explode('=', $argument, 2);
            }
            if (\in_array($argument, $withValue, true) && $value === null) {
                $value = $arguments[++$i] ?? throw new \InvalidArgumentException('Valeur manquante après ' . $argument . '.');
            }
            switch ($argument) {
                case '--source':
                    $parts = explode('=', (string) $value, 2);
                    if (\count($parts) !== 2 || !\in_array($parts[0], Platform::STATUSES, true) || $parts[1] === '') {
                        throw new \InvalidArgumentException('--source attend <liste>=<fichier.xlsx>, liste parmi : ' . implode(', ', Platform::STATUSES) . '.');
                    }
                    $options['sources'][$parts[0]] = $parts[1];
                    break;
                case '--manifeste':
                    $options['manifeste'] = (string) $value;
                    break;
                case '--donnees':
                    $options['donnees'] = rtrim((string) $value, '/');
                    break;
                case '--resume':
                    $options['resume'] = (string) $value;
                    break;
                case '--chargeur':
                    break;
                case '--accepter-colonnes':
                    $options['accepter-colonnes'] = true;
                    break;
                case '--aide':
                case '--help':
                case '-h':
                    $options['aide'] = true;
                    break;
                default:
                    throw new \InvalidArgumentException('Argument non reconnu : ' . $argument . '.');
            }
        }
        if (!is_dir($options['donnees'])) {
            throw new \InvalidArgumentException('Dossier des données introuvable : ' . $options['donnees'] . '.');
        }

        return $options;
    }

    /**
     * Manifeste : {"fichiers": [{"cle", "chemin", "url", "derniere_modification"}]}.
     *
     * @return array<string, array{chemin: string, url: string, derniere_modification: string|null}>
     *
     * @throws SourceError
     */
    private static function manifest(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new SourceError('Manifeste illisible : ' . $path . '.');
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SourceError('Manifeste : JSON invalide (' . $error->getMessage() . ').');
        }
        $inputs = [];
        foreach (\is_array($data) && \is_array($data['fichiers'] ?? null) ? $data['fichiers'] : [] as $entry) {
            $key = \is_array($entry) ? ($entry['cle'] ?? null) : null;
            $file = \is_array($entry) ? ($entry['chemin'] ?? null) : null;
            $url = \is_array($entry) ? ($entry['url'] ?? null) : null;
            $modified = \is_array($entry) ? ($entry['derniere_modification'] ?? null) : null;
            if (!\is_string($key) || !\is_string($file) || !\is_string($url) || ($modified !== null && !\is_string($modified))) {
                throw new SourceError('Manifeste : chaque fichier doit avoir « cle », « chemin », « url » et « derniere_modification » (texte ou null).');
            }
            $inputs[$key] = ['chemin' => $file, 'url' => $url, 'derniere_modification' => $modified];
        }
        if ($inputs === []) {
            throw new SourceError('Manifeste : aucun fichier.');
        }

        return $inputs;
    }

    /**
     * Écrit chaque fichier dans un fichier temporaire du même dossier, puis le renomme : un fichier n'est jamais
     * à moitié écrit.
     *
     * @param array<string, string> $files
     *
     * @throws SourceError
     */
    private static function write(string $directory, array $files): void
    {
        $temporaries = [];
        foreach ($files as $name => $content) {
            $temporary = $directory . '/.' . $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporary, $content) !== \strlen($content)) {
                foreach ($temporaries as $path) {
                    @unlink($path);
                }
                @unlink($temporary);

                throw new SourceError('Écriture impossible dans ' . $directory . '.');
            }
            @chmod($temporary, 0644);
            $temporaries[$name] = $temporary;
        }
        foreach ($temporaries as $name => $temporary) {
            if (!rename($temporary, $directory . '/' . $name)) {
                throw new SourceError('Remplacement impossible : ' . $name . '.');
            }
        }
    }
}
