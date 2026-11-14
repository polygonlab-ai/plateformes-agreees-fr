```
       ▄▄▀▀▄▄        ____       _                         _          _
    ▄▀▀  ▄▄  ▀▀▄    |  _ \ ___ | |_   _  __ _  ___  _ __ | |    __ _| |__
    █  ▄▀  ▀▄  █    | |_) / _ \| | | | |/ _` |/ _ \| '_ \| |   / _` | '_ \
    █  ▀▄  ▄▀  █    |  __/ (_) | | |_| | (_| | (_) | | | | |__| (_| | |_) |
    ▀▄▄  ▀▀  ▄▄▀    |_|   \___/|_|\__, |\__, |\___/|_| |_|_____\__,_|_.__/
       ▀▀▄▄▀▀                     |___/ |___/
                                   polygonlab.ai
```

# plateformes-agreees-fr

> Liste officielle des plateformes agréées pour la facture électronique, en JSON et CSV, mise à jour chaque jour.

[![CI](https://github.com/polygonlab-ai/plateformes-agreees-fr/actions/workflows/ci.yml/badge.svg)](https://github.com/polygonlab-ai/plateformes-agreees-fr/actions/workflows/ci.yml)
[![Version](https://img.shields.io/github/v/release/polygonlab-ai/plateformes-agreees-fr)](https://github.com/polygonlab-ai/plateformes-agreees-fr/releases)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)

## Pourquoi

Avec la réforme de la facture électronique, chaque entreprise doit passer par une **plateforme agréée** (PA)
pour émettre et recevoir ses factures. La DGFiP publie la liste officielle sur impots.gouv.fr, en fichiers
ODS, XLSX et PDF. Cette liste change souvent, et les copies qu'on trouve ailleurs sont vite périmées.

Ce dépôt en tient une copie **fiable, datée et historisée**, dans des formats faciles à réutiliser : un
fichier JSON avec son schéma, un fichier CSV qui s'ouvre directement dans Excel, et l'historique de chaque
entrée, sortie ou changement de statut. Les deux listes officielles sont réunies, avec pour chaque plateforme
son nom commercial, l'adresse de son établissement principal, son site internet et, pour les plateformes
immatriculées, la date de délivrance de leur numéro d'immatriculation. La source est vérifiée **chaque jour** ; une nouvelle version est
publiée dès que la liste officielle change.

## Fonctionnalités

- Les deux listes de la DGFiP réunies, avec un statut par plateforme : `immatriculee` ou `attente_tests_interoperabilite`.
- Clé stable d'une version à l'autre, tirée du nom commercial.
- Dates d'immatriculation converties au format ISO (`AAAA-MM-JJ`).
- Historique daté : entrées, sorties, changements de statut et de nom.
- Traçabilité de la source : adresse, empreinte SHA-256 et date de chaque fichier lu.
- Schéma JSON du fichier principal, vérifié à chaque publication.
- Outil d'actualisation en PHP, utilisable par n'importe qui pour refaire la copie soi-même.

## Installation

Rien à installer pour lire les données : les fichiers se téléchargent directement depuis GitHub.

| Fichier | Adresse directe |
|---|---|
| Liste (JSON) | `https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/plateformes.json` |
| Liste (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/plateformes.csv` |
| Historique (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/historique.csv` |
| Source (JSON) | `https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/source.json` |

Pour figer une version, remplacer `main` par une étiquette, par exemple `v0.1.0`.

## Démarrage rapide

```php
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
```

Sortie attendue (avec une liste d'exemple) :

```text
166 plateformes, liste du 2026-11-12
Exemple Facturation (Paris) : immatriculée le 2025-12-11
```

## Utilisation

**En ligne de commande**, avec `curl` et `jq` : les noms commerciaux des plateformes immatriculées.

```bash
curl -s https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/plateformes.json \
  | jq -r '.plateformes[] | select(.statut == "immatriculee") | .nom_commercial'
```

**Dans Excel** : ouvrir directement `data/plateformes.csv` (UTF-8 avec BOM, séparateur point-virgule).

**Suivre les changements** : `data/historique.csv` ne fait que s'allonger. Les dernières lignes disent ce qui a
changé depuis votre dernière lecture.

```bash
curl -s https://raw.githubusercontent.com/polygonlab-ai/plateformes-agreees-fr/main/data/historique.csv | tail -n 20
```

**Être prévenu** : sur GitHub, « Watch », puis « Custom » et « Releases » : chaque changement de la liste
donne une version.

## Format des fichiers

### `data/plateformes.json`

Schéma : [`schema/plateformes.schema.json`](schema/plateformes.schema.json).

| Champ | Type | Contenu |
|---|---|---|
| `source` | texte | « DGFiP, impots.gouv.fr » |
| `page` | texte | Page officielle de la liste |
| `date_source` | date ou null | Date de la liste : date écrite dans les fichiers, sinon date de modification annoncée par le serveur, sinon date de lecture |
| `nombre` | entier | Nombre total de plateformes |
| `nombres` | objet | Nombre de plateformes par statut |
| `plateformes` | liste | Plateformes, triées par clé |

Chaque plateforme :

| Champ | Type | Contenu |
|---|---|---|
| `cle` | texte | Clé stable : `nom:` suivi du nom commercial simplifié (minuscules sans accent, mots séparés par des tirets) |
| `nom_commercial` | texte | Nom commercial de la plateforme, tel qu'écrit par la DGFiP |
| `statut` | texte | `immatriculee` (toutes les conditions remplies, tests d'interopérabilité compris) ou `attente_tests_interoperabilite` (dossier complet, tests à passer) |
| `adresse.voie` | texte ou null | Voie de l'établissement principal (pour une plateforme étrangère, elle peut contenir la ville) |
| `adresse.code_postal` | texte ou null | Code postal : 5 chiffres pour une adresse française, tel qu'écrit dans le fichier pour une adresse étrangère (`null` s'il est absent) |
| `adresse.commune_ou_pays` | texte ou null | Commune pour une plateforme française, pays pour une plateforme étrangère (comme dans le fichier de la DGFiP) |
| `adresse.pays` | texte | `France`, ou le pays étranger reconnu dans `commune_ou_pays` |
| `site_internet` | texte ou null | Site internet, tel qu'écrit par la DGFiP (`https://` ou `http://`) |
| `date_immatriculation` | date ou null | Date de délivrance du numéro d'immatriculation (`AAAA-MM-JJ`) ; `null` si la DGFiP écrit « En attente », et pour une plateforme en attente des tests d'interopérabilité |
| `immatriculation_en_attente` | booléen | `true` quand la DGFiP écrit « En attente » à la place de la date de délivrance ; `false` sinon, et toujours `false` pour une plateforme en attente des tests d'interopérabilité |

Un champ que la DGFiP laisse vide vaut `null`. Les espaces en trop sont retirés ; aucune autre valeur n'est
modifiée.

**Date « En attente ».** Dans la liste des plateformes immatriculées, la DGFiP écrit « En attente » à la place de la
date de délivrance du numéro d'immatriculation quand cette date n'est pas encore connue. Ce texte, et lui seul, est
accepté : `date_immatriculation` vaut alors `null` et `immatriculation_en_attente` vaut `true`. Tout autre texte qui
n'est pas une date arrête la mise à jour.

**Codes postaux et pays.** Dans les fichiers de la DGFiP, la plupart des codes postaux sont enregistrés comme des
nombres : les codes français ont perdu leur zéro de tête (« 6200 » pour Nice). La règle appliquée ne devine rien :

1. l'adresse est **étrangère** si `commune_ou_pays`, ou le texte entre parenthèses à sa fin (« Espoo (Finlande) »),
   est un nom de pays d'une liste fermée embarquée (norme ISO 3166-1, noms en français, avec ou sans accents :
   `tools/src/Countries.php`) ; sinon elle est **française** ;
2. adresse française : le code doit avoir 4 ou 5 chiffres ; 4 chiffres reçoivent un `0` en tête (`06200`) ; tout
   autre format arrête la mise à jour ;
3. adresse étrangère : le code est repris tel quel (`111 51`, `D04TR29`, `1211 BT`).

Les territoires d'outre-mer qui utilisent les codes postaux français (La Réunion, Guadeloupe…) sont des adresses
françaises.

**Pas de SIREN.** Les fichiers de la DGFiP ne donnent ni SIREN, ni raison sociale, ni numéro d'immatriculation :
seul le nom commercial identifie la plateforme. Ce dépôt ne cherche pas à en déduire un SIREN (rapprochement par
le nom, annuaire des entreprises) : ce serait deviner, et une erreur serait recopiée partout.

**Pas de courriel.** Les fichiers de la DGFiP donnent une adresse de contact par plateforme ; elle n'est jamais
recopiée ici. Les adresses de contact sont sur la page de la DGFiP.

### `data/plateformes.csv`

Les mêmes plateformes, une par ligne, dans le même ordre : colonnes `cle`, `nom_commercial`, `statut`,
`adresse_voie`, `adresse_code_postal`, `adresse_commune_ou_pays`, `adresse_pays`, `site_internet`,
`date_immatriculation`, `immatriculation_en_attente` (`oui` ou `non`).
UTF-8 avec BOM, séparateur `;`, valeurs entre guillemets seulement si nécessaire.

### `data/historique.csv`

Une ligne par événement : `date` (jour de la lecture), `evenement` (`entree`, `sortie`, `changement_statut`,
`changement_nom`), `cle`, `statut`, `nom_commercial`, `detail`. Le fichier ne fait que s'allonger. Un changement
de nom ne concerne que l'écriture (majuscules, accents, ponctuation) : un nom vraiment différent donne une
nouvelle clé, donc une sortie et une entrée.

### `data/source.json`

Pour chaque fichier lu : clé de la liste, nom du fichier, adresse, empreinte SHA-256, taille, date de
modification annoncée par le serveur, date écrite dans le fichier, feuille et colonnes lues, nombre de lignes.
Plus la date de lecture (`lu_le`) et la date de la liste (`date_source`).

## Configuration

L'outil d'actualisation refait la copie à partir des fichiers officiels, sans aucune bibliothèque à installer :

```bash
php tools/actualiser.php
```

| Option | Type | Valeur par défaut | Rôle |
|---|---|---|---|
| `--source <liste>=<fichier.xlsx>` | chemin | téléchargement | Fichier déjà téléchargé (`immatriculee` ou `attente_tests_interoperabilite`) |
| `--manifeste <fichier.json>` | chemin | aucun | Fichiers déjà téléchargés, avec leur date de modification |
| `--donnees <dossier>` | chemin | `data/` | Dossier des données à mettre à jour |
| `--resume <fichier.json>` | chemin | aucun | Résumé des changements, en JSON |
| `--accepter-colonnes` | option | non | Accepte des colonnes différentes de la lecture précédente |
| `--chargeur <fichier.php>` | chemin | `vendor/autoload.php` | Chargeur automatique à utiliser à la place de celui de Composer |

L'outil ne devine jamais une colonne : il reconnaît chaque intitulé, en-tête sur deux lignes compris
(`tools/src/Columns.php`), vérifie que chaque fichier a exactement les colonnes attendues (`tools/sources.json` :
la date de délivrance n'existe que dans la liste des plateformes immatriculées), et s'arrête sans rien écrire
devant une colonne inconnue, manquante ou en trop, des intitulés changés depuis la lecture précédente, une date
illisible (autre que « En attente »), un code postal français qui n'a pas 4 ou 5 chiffres, une adresse e-mail hors de la colonne des courriels, un nom commercial absent ou en double, une liste
vide ou une plateforme présente dans les deux listes.

## Limites

- **Seule la liste publiée sur impots.gouv.fr fait foi.** Ce dépôt en est une copie, vérifiée chaque jour : il
  peut y avoir un décalage de quelques heures après une mise à jour officielle.
- Les champs sont ceux que publie la DGFiP, sans SIREN ni courriel (voir « Format des fichiers »).
- La clé dépend du nom commercial : si la DGFiP renomme une plateforme, l'historique montre une sortie et une entrée.
- Ce dépôt ne dit rien de la qualité d'une plateforme : il recopie la liste officielle, sans avis.

## Source et licence des données

Source : DGFiP, impots.gouv.fr, Licence Ouverte 2.0 —
[Je consulte la liste des plateformes agréées](https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees).
La date des fichiers copiés est donnée par `date_source` et, fichier par fichier, dans `data/source.json`.

Les informations du site impots.gouv.fr sont publiées sous
[Licence Ouverte / Open Licence version 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence/) (Etalab),
sauf droits de tiers : elles sont réutilisées ici avec mention de leur source et de leur date. Les éléments
graphiques de la DGFiP, dont les logos « plateforme agréée », lui appartiennent et ne sont pas reproduits.

Le code (outil, schéma, tests) est sous licence MIT.

## Compatibilité

Outil d'actualisation : PHP 8.2, 8.3 et 8.4 (extensions zip et xmlreader, aucune autre bibliothèque), testés par
l'intégration continue.
Les données se lisent avec n'importe quel langage.

## Tests

```bash
composer install
composer test
```

Les tests utilisent des classeurs fabriqués, sans appel réseau, et vérifient que `data/` respecte le schéma.

## Contribuer

Voir [CONTRIBUTING.md](https://github.com/polygonlab-ai/.github/blob/main/CONTRIBUTING.md).

## Sécurité

Une faille se signale en privé, jamais dans un ticket public : voir
[SECURITY.md](https://github.com/polygonlab-ai/.github/blob/main/SECURITY.md).

## Licence

Code sous licence MIT : voir [LICENSE](LICENSE). Données : voir « Source et licence des données ».

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
