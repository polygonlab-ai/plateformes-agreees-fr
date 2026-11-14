<?php

declare(strict_types=1);

// Liste publiée sur GitHub (ou copie locale passée en argument).
$adresse = $argv[1] ?? 'https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/plateformes.json';
/** @var array{nombre: int, date_source: string, plateformes: list<array{nom_commercial: string, statut: string, adresse: array{commune_ou_pays: ?string}, date_immatriculation: ?string}>} $liste */
$liste = json_decode((string) file_get_contents($adresse), true, 16, JSON_THROW_ON_ERROR);

printf("%d plateformes, liste du %s\n", $liste['nombre'], $liste['date_source']);

// Les plateformes immatriculées, avec leur date d'immatriculation.
foreach ($liste['plateformes'] as $plateforme) {
    if ($plateforme['statut'] === 'immatriculee') {
        printf("%s (%s) : immatriculée le %s\n", $plateforme['nom_commercial'], $plateforme['adresse']['commune_ou_pays'] ?? '?', $plateforme['date_immatriculation'] ?? '?');
    }
}
