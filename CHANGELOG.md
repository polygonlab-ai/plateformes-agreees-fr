# Journal des changements

Toutes les évolutions notables de ce dépôt sont décrites ici : à partir de la version 0.1.1, chaque
changement de la liste officielle donne une version de correctif, publiée automatiquement.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte
le [versionnage sémantique](https://semver.org/lang/fr/).

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

[0.1.0]: https://github.com/polygonlab-ai/plateformes-agreees-fr/releases/tag/v0.1.0

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
