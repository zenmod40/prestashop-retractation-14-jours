# Changelog — Rétractation Commande

Toutes les évolutions notables du module. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [1.4.4] — 2026-06-29

### Ajouté
- **Photos jointes par le client** : option permettant au client de joindre des photos (état du produit et de son emballage) lors de la demande de rétractation. Facultatif et jamais bloquant. Chaque image est validée par le système natif PrestaShop (`ImageManager`) **puis ré-encodée** (reconstruction à partir des pixels, ce qui détruit tout contenu malveillant éventuellement embarqué), stockée hors d'accès direct et consultable en back-office sur la fiche de la demande. Activable depuis la configuration (« Autoriser les photos »). Formats acceptés : JPG, PNG, WebP, GIF (jamais de SVG) ; 4 photos maximum, 4 Mo chacune.
- **Adresse de retour** : nouveau champ de configuration pour indiquer une adresse de retour distincte de celle de la boutique (centre logistique, entrepôt, prestataire…). Elle s'affiche sur l'accusé de réception PDF et dans la procédure de retour envoyée au client à l'acceptation. Laissé vide, l'adresse de la boutique est utilisée comme avant.

### Modifié
- La liste des autres modules ZM40 (écosystème) passe dans un **onglet dédié « Modules ZM40 »** de la page de configuration, au lieu d'un bloc en bas de page.

## [1.4.3] — 2026-06-25

### Ajouté
- **Sélecteur de catégories visuel** : la saisie des catégories exclues se fait désormais via un **arbre de catégories** (cases à cocher), avec un champ de **recherche instantanée** en haut qui filtre l'arborescence en direct — au lieu d'une liste d'IDs séparés par des virgules.
- **Sélecteur de produits avec recherche** : champ d'**autocomplétion** (recherche par nom ou référence, ajout/retrait sous forme d'étiquettes) pour les produits exclus, au lieu d'une liste d'IDs.
- **CSS personnalisé (front)** : nouveau champ en configuration permettant d'adapter typographie et couleurs des pages de rétractation à la charte graphique de la boutique, **sans accès FTP ni surcharge de thème** (injection sécurisée, anti-injection HTML).

### Modifié
- Les pages front du module **héritent explicitement** de la typographie du thème (boutons et champs inclus, qui ne suivaient pas la police du thème par défaut).

## [1.4.2] — 2026-06-25

### Ajouté
- **Header d'administration ZM40** : bandeau dégradé commun à la gamme ZM40 en haut de la page de configuration (nom du module, sous-titre, version, boutique).
- **Panneau « L'écosystème ZM40 »** : liste des autres modules ZM40 disponibles (open source et pro), alimentée par un feed distant anonyme et fail-silent — badge « Déjà installé » et bouton « Configurer » pour les modules présents sur la boutique. Désactivable comme tout appel réseau ZM40 (`ZM40_NET_ENABLED`).

### Corrigé
- **Compatibilité PrestaShop 9.1** : le contrôleur d'administration (Demandes) utilisait la méthode de traduction legacy `l()`, retirée des contrôleurs admin en PrestaShop 9 (erreur fatale `UndefinedMethodError`). Ajout d'une couche de compatibilité (délégation au natif sur 1.7/8, sinon à `Module::l()`).
- **Compatibilité PrestaShop 9.1** : appel à `Tools::displayDate()` avec 3 arguments dans le parcours de demande (accusé de réception) — la signature ne prend plus que 2 arguments en PrestaShop 9. Merci au contributeur ayant remonté ces deux points.

## [1.4.1] — 2026-06-25

### Corrigé
- **Parcours invité** : appel à une méthode inexistante `Validate::isOrderReference()` qui provoquait une erreur fatale lorsqu'un visiteur non connecté (sans compte) soumettait le formulaire de recherche par email + référence. Remplacé par la méthode native `Validate::isReference()`.

## [1.4.0] — 2026-06-20

### Modifié
- **Licence MIT → GPL v3.** Uniformisation avec le reste du catalogue open source ZM40 (CoolStats, ShortCodes, etc.). Le code reste libre ; les dérivés doivent désormais rester sous une licence GPL-compatible (copyleft fort) au lieu de pouvoir être close-sourcés. Les versions antérieures (1.0 à 1.3.1) restent disponibles sous MIT pour ceux qui les ont déjà téléchargées.
- Page de configuration BO : bloc « libre & open source » mis à jour (mention GPL v3 + lien vers zm40.com — recentrage sur la marque catalogue ZM40, la marque atelier Magic Garden reste mentionnée).

## [1.3.1] — 2026-06-15

### Ajouté
- **Onglet « Mapping des statuts »** dans la configuration (3 onglets : Configuration · Mapping des statuts · Clause CGV). Matrice interactive associant chaque statut de commande à un rôle :
  - **« Livré »** — démarre le délai légal de 14 jours, en complément du drapeau natif PrestaShop (le décompte peut partir de la date d'entrée dans un état mappé, via `order_history`).
  - **« Expédié (en cours d'acheminement) »** — colis parti mais non encore livré : le client peut refuser le colis ou le renvoyer (le délai de 14 jours ne démarre qu'à la livraison).
  - **« Bloquant »** — masque le bouton de rétractation, en plus des états annulé / remboursé / erreur (toujours bloquants).
- **Remplissage automatique dynamique** : détection de chaque statut via ses drapeaux PrestaShop (`shipped`, `delivery`, `paid`) et des mots-clés multilingues du nom (livré, expédié, annulé, remboursé, delivered, shipped, cancel…). Pré-rempli dès l'installation.
- Matrice avec pastille de couleur, nombre de commandes sur 12 mois, drapeaux, recherche, résumé en direct et détection de conflits.
- **3ᵉ parcours « en transit »** : textes adaptés (modal, accusé PDF, emails, écran SAV) pour une commande expédiée non encore livrée — « refusez le colis ou suivez la procédure de retour » au lieu de « annulation, rien à renvoyer ». Phase logistique figée au dépôt (colonne `shipping_phase`) et affichée au SAV. Nouvelles chaînes traduites dans les 8 langues.

### Modifié
- L'éligibilité s'appuie désormais sur une **date de livraison effective** (drapeau natif OU état mappé « Livré »), une **phase logistique** (livré / expédié / non expédié) et une **liste d'états bloquants** (3 natifs + mapping).
- La validation SAV envoie la procédure de retour pour les commandes livrées **et** en transit, et l'email d'annulation uniquement pour les commandes non expédiées.

## [1.3.0] — 2026-06-14

### Ajouté
- **Compatibilité PrestaShop 9** (déclaration de compatibilité étendue à 9.x).

### Modifié
- Suppression des appels dépréciés : formatage des prix via `Locale::formatPrice()` (au lieu de `Tools::displayPrice`), date du formulaire générée côté PHP (plus de `strftime` déprécié en PHP 8.1).

## [1.2.0] — 2026-06-14

### Ajouté
- **Multilingue** : interface (116 chaînes) et emails (6 × HTML/texte) traduits en EN, ES, DE, IT, NL, PT, PL.
- **Clause CGV** étendue aux 8 langues, affichée par langue installée dans la configuration.

## [1.1.0] — 2026-06-14

### Ajouté
- **Lien « Exercer mon droit de rétractation »** en pied de page (activable/désactivable, réservé aux clients connectés) et dans l'espace client.
- **Page dédiée** `/retractation` avec **parcours invité** (email + référence de commande).
- **Rétractation partielle** : sélection des produits et quantités dans la modal.
- **Référence non séquentielle type RMA** (RET-XXXXXXXX).
- **PDF enrichi** : logo, bloc « Boutique », références produits, prix, totaux, montant à rembourser, page 2 « Rappel de vos droits ».
- **Logo dans les emails**, email « rétractation remboursée », email « annulation avant expédition ».
- **Clause CGV bilingue** prête à copier.
- **Délai configurable** (14 jours minimum légal), **masquage du formulaire de retour natif** (option), badge de statut sur la ligne de commande.

### Corrigé
- Doublon du bouton sur « Mes commandes », bouton stylisé.
- Route `moduleRoutes` (page de rétractation en 404).
- Conservation de la table à la désinstallation (preuves légales).
- Validation défensive de `delivery_date` (NULL, `0000-00-00`, format invalide).
- Textes adaptatifs « avant expédition » (annulation) vs « après livraison » (retour produit) dans la modal, le PDF, les emails et l'écran SAV.
- Email/PDF sans mention superflue de l'email boutique ni virgule orpheline.

## [1.0.0] — 2026-06-13

### Ajouté
- Bouton « Se rétracter » dans l'espace client, affiché uniquement pendant le délai légal.
- Calcul du délai de 14 jours (départ le lendemain de la livraison, prolongation au premier jour ouvrable si échéance un samedi, dimanche ou jour férié français).
- Modal avec formulaire type de rétractation prérempli (annexe art. R221-1).
- Accusé de réception PDF + emails (client et SAV).
- Onglet back-office « SAV > Rétractations » adossé aux retours produits natifs (création + synchronisation des états).
- Configuration : email SAV, exclusions L221-28 (produits / catégories), texte de procédure de retour.
