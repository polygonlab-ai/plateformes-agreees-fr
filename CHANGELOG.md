# Journal des changements

Toutes les évolutions notables de ce dépôt sont décrites ici : à partir de la version 0.1.1, chaque
changement de la liste officielle donne une version de correctif, publiée automatiquement.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte
le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.2.2] - 2026-11-21 - Intégration continue au vert

### Corrigé

- Lecteur ODS : la fin du document est lue jusqu'au bout après le classeur, pour qu'un fichier coupé soit refusé quelle que soit la version de libxml (le test `testRefusesAnUnreadableContent` échouait sur l'intégration continue). Données inchangées.

## [0.2.1] - 2026-11-21 - Liste du 21/11/2026 : 25 changements

### Entrées dans une liste

- Inexchange ApS (Danemark) (immatriculée)
- WISETECH (Australie) (immatriculée)

### Sorties

- Legalinvoice by Tinexta Infocert (Italie)
- MySupply Aps (Danemark)
- WiseTech GLOBAL (Australie)

### Changements de statut

- EY Expertises & Transactions (Courbevoie) : en attente des tests d'interopérabilité -> immatriculée

### Changements de nom

- A-CUBE (Italie) : A-Cube -> A-CUBE
- CHAINTRUST BY VISMA (Paris) : CHAINTRUST by Visma -> CHAINTRUST BY VISMA
- Eezi-powered by VAT IT (SaintGermain-en-Laye) : EEZI powered by VAT IT -> Eezi-powered by VAT IT
- PONSOT CONSEIL GESTION (Aix-en-Provence) : Ponsot Conseil Gestion -> PONSOT CONSEIL GESTION
- TRANSALIS LIMITED (Chypre) : Transalis Limited -> TRANSALIS LIMITED

### Autres modifications

- BE FRESH S.à r.l. (Luxembourg) : date_immatriculation (vide) -> 2026-10-12
- BE FRESH S.à r.l. (Luxembourg) : immatriculation_en_attente oui -> non
- EY Expertises & Transactions (Courbevoie) : date_immatriculation (vide) -> 2026-11-19
- FACTUREAPP (Lyon) : date_immatriculation (vide) -> 2026-10-09
- FACTUREAPP (Lyon) : immatriculation_en_attente oui -> non
- KALANDA (La Ciotat) : date_immatriculation (vide) -> 2026-10-09
- KALANDA (La Ciotat) : immatriculation_en_attente oui -> non
- MY-EDDY (Rillieux-la-Pape) : date_immatriculation (vide) -> 2026-10-12
- MY-EDDY (Rillieux-la-Pape) : immatriculation_en_attente oui -> non
- OPEN BEE (Epagny Metz-Tessy) : date_immatriculation 2026-01-13 -> 2026-01-12
- PONSOT CONSEIL GESTION (Aix-en-Provence) : date_immatriculation (vide) -> 2026-11-02
- PONSOT CONSEIL GESTION (Aix-en-Provence) : immatriculation_en_attente oui -> non
- ZEFYR (Paris) : date_immatriculation (vide) -> 2026-11-02
- ZEFYR (Paris) : immatriculation_en_attente oui -> non

### Listes

- Plateformes qui remplissent toutes les conditions, tests d'interopérabilité compris : 159 plateformes
- Plateformes au dossier complet, en attente des tests d'interopérabilité : 5 plateformes

## [0.2.0] - 2026-11-21 - Lecture des listes au format ODS quand le fichier XLSX n'est plus servi

### Ajouté

- Lecteur de classeur ODS (`tools/src/OdsReader.php`), sans bibliothèque extérieure : il rend le même tableau que le lecteur XLSX (cellules et lignes répétées, cellules fusionnées, nombres, dates, texte en plusieurs morceaux). La même liste donne le même résultat dans les deux formats.
- Formats de repli dans `tools/sources.json` (champ `replis`) : chaque liste déclare son fichier ODS officiel à côté de son fichier XLSX. Le fichier XLSX est toujours essayé le premier ; s'il n'est plus servi par la source (réponse HTTP 404 ou 410), la même liste est lue au format ODS, et le fichier XLSX est repris de lui-même à son retour.
- `--source <liste>=<fichier>` accepte un fichier ODS. Le PDF n'est pas lu : un tableau ne s'en extrait pas de façon sûre.

### Modifié

- `data/source.json` garde le nom, l'adresse et l'empreinte du fichier réellement lu, et non plus toujours le nom du fichier XLSX attendu.
- Intitulés de colonnes comparés à ceux de la lecture précédente : à l'identique dans le même format, sans tenir compte de la typographie (apostrophes, accents, majuscules, espaces) d'un format à l'autre.
- Tout fichier fourni qui n'est ni un XLSX ni un ODS est refusé, d'après son extension.
- Données inchangées.

## [0.1.2] - 2026-11-17 - Liste du 17/11/2026 : 2 autres modifications

### Autres modifications

- Basikon (Garches) : date_immatriculation (vide) -> 2026-11-09
- Basikon (Garches) : immatriculation_en_attente oui -> non

### Listes

- Plateformes qui remplissent toutes les conditions, tests d'interopérabilité compris : 159 plateformes
- Plateformes au dossier complet, en attente des tests d'interopérabilité : 6 plateformes

## [0.1.1] - 2026-11-14 - Intégration continue au vert

### Corrigé

- Tests : la méthode d'aide `run()` de `tests/UpdaterTest.php` masquait la méthode finale `TestCase::run()` de PHPUnit et arrêtait toute la suite de tests ; elle s'appelle désormais `update()`.
- Analyse statique PHPStan au niveau maximal : classeurs d'essai typés comme des cellules indexées par colonne, en-tête de l'historique déclaré non vide, deux contrôles redondants retirés dans `Downloader`, calcul de la dernière colonne protégé contre une ligne d'en-tête vide dans `ListReader`.
- Données inchangées.

## [0.1.0] - 2026-11-14 - Première publication de la liste des plateformes agréées

### Ajouté

- Copie de la liste officielle des plateformes agréées publiée par la DGFiP sur impots.gouv.fr, avec ses deux listes : plateformes immatriculées et plateformes en attente des tests d'interopérabilité.
- `data/plateformes.json` et `data/plateformes.csv` : liste courante ; pour chaque plateforme, nom commercial, statut, adresse de l'établissement principal (voie, code postal, commune ou pays, pays), site internet et date de délivrance du numéro d'immatriculation.
- `data/historique.csv` : entrées, sorties, changements de statut et de nom, datés.
- `data/source.json` : adresse, empreinte SHA-256, date et colonnes de chaque fichier lu.
- `schema/plateformes.schema.json` : schéma JSON du fichier principal.
- `tools/actualiser.php` : outil sans bibliothèque extérieure qui lit les fichiers officiels (en-tête sur deux lignes, dates au format Excel), contrôle leurs colonnes, et réécrit les données.
- « En attente » à la place de la date de délivrance (date pas encore connue) : `date_immatriculation` à `null` et `immatriculation_en_attente` à `true` (`oui` dans le CSV).
- Codes postaux français rétablis sur 5 chiffres (zéro de tête perdu par le fichier source) ; adresses étrangères reconnues par une liste fermée de pays (ISO 3166-1 en français), leur code postal repris tel quel.
- Les adresses de contact des fichiers officiels ne sont jamais recopiées ; aucun SIREN n'est déduit du nom (les fichiers n'en donnent pas).
- Source : DGFiP, impots.gouv.fr, Licence Ouverte 2.0.

[0.2.2]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.2.2
[0.2.1]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.2.1
[0.2.0]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.2.0
[0.1.2]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.1.2
[0.1.1]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.1.1
[0.1.0]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.1.0

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
