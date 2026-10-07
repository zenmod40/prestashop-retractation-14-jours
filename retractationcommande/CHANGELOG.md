# Changelog — Rétractation Commande

Toutes les évolutions notables du module. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [1.6.3] — 2026-10-07

### Modifié

- **Onglet « Modules ZM40 » toujours présent**, avec la liste des modules puis un interrupteur réseau, qui n'existait pas dans la configuration. Textes traduits dans les huit langues du module.
- **Interrupteur réseau coupé : la liste des modules reste affichée.** Il arrête seulement sa mise à jour depuis zm40.com ; avant, la liste disparaissait. Composant commun ZM40 1.4, qui n'affiche plus non plus le module dans sa propre liste.

## [1.6.2] — 2026-10-03

### Ajouté
- **Bon de retour PDF facultatif.** Nouvel interrupteur en configuration, « Joindre un bon de retour PDF ». Désactivé, l'e-mail de validation d'une demande part sans le bon de retour en pièce jointe et sans l'encadré qui invite le client à l'imprimer et à le coller sur le colis ; la procédure, l'adresse de retour et les instructions spécifiques restent dans l'e-mail. Utile aux boutiques qui n'exigent pas de bordereau à réception des colis. L'option est activée par défaut, à l'installation comme à la mise à jour : rien ne change sans action de votre part.

## [1.6.1] — 2026-09-30

### Sécurité
- **Correctif de sécurité, mise à jour recommandée.** Le motif saisi par le client était recopié sans être neutralisé dans le retour produit natif, que le back-office affiche tel quel : un client pouvait y placer du code exécuté dans le navigateur de l'employé qui ouvrait le retour. Le motif est désormais enregistré en texte brut (2000 caractères au plus) et neutralisé dans le retour natif ; le script de mise à jour neutralise aussi les motifs des retours déjà créés. Le détail sera publié ultérieurement dans une note de sécurité.
- **Droits des employés.** Accepter, refuser et marquer remboursée une demande exigent le droit de modification sur l'onglet Rétractations ; télécharger le PDF ou les photos, le droit de consultation. Un profil en lecture seule pouvait jusqu'ici effectuer ces gestes.

### Ajouté
- **Référence de commande dans SAV > Retours produits.** La liste native n'affichait que l'identifiant de la commande ; le module ajoute la colonne « Référence commande », filtrable.
- **Hook d'éligibilité `actionRetractationcommandeEligibility`.** Un autre module peut réclamer une commande dont il traite lui-même la rétractation (par exemple une marketplace, pour les commandes de ses vendeurs) : il reçoit la commande (`order`) et écrit son nom dans `claimed_by`. La commande n'a alors ni bouton ni dépôt ici ; le formulaire invité et le dépôt en ligne renvoient le client vers le vendeur. Constante `RetractationRequest::ELIGIBILITY_HOOK`.

### Corrigé
- **Parcours invité.** La recherche d'une commande par e-mail et référence finissait en erreur 500 depuis la 1.5.0 : les règles de retour du client étaient demandées avec la ligne de la commande au lieu de la commande.

## [1.6.0] — 2026-09-27

### Ajouté
- **Gestes SAV appelables hors back-office.** Accepter, refuser et marquer remboursée une demande passent désormais par une classe publique, `RetractationWorkflow`, que le back-office et l'application Régie (via son module `regiebridge`) appellent tous deux : mêmes e-mails, même bon de retour PDF, même synchronisation du retour natif, quel que soit le point d'entrée. La classe fonctionne depuis un contrôleur front ou la ligne de commande, l'employé étant passé en paramètre.
- **Historique des demandes.** Nouvelle table `retractation_request_history` : chaque changement d'état y est consigné avec l'employé, l'origine (back-office, Régie, dépôt client) et le motif d'un refus. Créée par le script de mise à jour pour les installations existantes ; les demandes déjà enregistrées ne sont pas touchées.

### Corrigé
- **Double geste.** Le contrôle « on n'accepte ou ne refuse qu'une demande à vérifier » n'existait que dans l'affichage : un double clic, ou un formulaire resté ouvert, renvoyait les e-mails et pouvait repasser une demande remboursée à l'état « acceptée ». La transition est maintenant contrôlée en base, dans la requête d'écriture elle-même : une demande déjà traitée n'est plus modifiée et aucun e-mail ne part. Le back-office affiche une erreur lisible.
- **E-mail « remboursée » d'une commande en cours d'acheminement.** Son introduction était choisie selon la date de livraison, absente pour une demande déposée pendant le transport : le client lisait « votre commande a été annulée avant expédition ». Elle suit désormais la phase d'expédition figée au dépôt, comme l'e-mail de validation.
- **Retour natif orphelin.** Le retour PrestaShop était créé avant la demande ; si l'enregistrement de la demande échouait, il restait seul dans SAV > Retours produits. La demande est désormais enregistrée d'abord.
- Le back-office signale quand l'e-mail au client n'a pas pu partir ou quand le bon de retour PDF n'a pas pu être généré, au lieu de confirmer en silence.

## [1.5.0] — 2026-09-03

### Ajouté
- **Règles de retour par groupe de clients.** Le droit de rétractation de quatorze jours est un droit du consommateur ; un professionnel n'en a pas, et ce qui s'applique à lui est la politique commerciale du marchand. Nouvel onglet « Règles par groupe » : chaque groupe suit le jeu par défaut (la configuration actuelle, inchangée) ou reçoit un jeu propre — délai en jours sans plancher légal, libellé du lien, titre et intitulé du formulaire, conditions affichées avant l'envoi, procédure envoyée après validation, catégories et produits exclus. Le jeu d'un client est choisi sur son groupe par défaut (`id_default_group`), le critère que PrestaShop utilise pour les prix. Un libellé de lien vide masque le lien à ce groupe (retours traités par contact).
- **Vocabulaire commercial pour un jeu par groupe** : page de demande, fenêtre de confirmation, détail de commande, badges de statut, messages de succès et e-mails ne parlent plus de rétractation ni du Code de la consommation. Les e-mails passent par un template neutre (`retour_notification`, FR et EN), sans accusé de réception PDF légal.

- **Choix du bloc de pied de page où insérer le lien.** Nouveau champ en configuration : le sélecteur CSS de la liste visée, par exemple `#footer_linklist_2 ul` ou `.ps-customeraccountlinks ul` pour le bloc « Votre compte ». Laissé vide (valeur par défaut), le comportement ne change pas : le lien est ajouté à la dernière liste de liens CMS du pied de page. Un sélecteur qui ne correspond à rien retombe sur ce comportement, puis sur le bloc autonome.

### Corrigé
- **Bouton absent de l'historique des commandes sur Hummingbird.** Le script ne reconnaissait la ligne de commande que sous forme de `<tr>` (thème classic) ou de bloc `.order`. Hummingbird — et donc ses thèmes enfants — rend l'historique comme une grille de `<div>` et `<span>` porteurs des rôles ARIA de tableau : aucune ligne trouvée, le bouton n'était jamais posé, sans la moindre erreur. Les lignes sont désormais reconnues par leur rôle `row` autant que par la balise, et la cellule d'actions sous ses trois noms (`.order-actions`, `.order__actions`, `.order-history__cell--actions`), avec repli sur la dernière cellule de la ligne — un `<div>` ajouté directement dans un `<tr>` n'était de toute façon jamais rendu.
- **Thème enfant de Hummingbird** : le lien de l'espace client utilisait le markup du thème classic quand le thème actif est un enfant de Hummingbird (nom différent). Le thème parent est désormais pris en compte.
- **Parcours invité : jeu de règles du client au lieu du jeu par défaut.** La page de demande calculait le jeu avant de retrouver la commande par e-mail et référence, sans le recalculer ensuite : un professionnel non connecté lisait « droit de rétractation » et quatorze jours, alors que l'envoi était validé sur son jeu — l'affichage annonçait une règle que le traitement ne suivait pas. Le jeu est désormais celui du client de la commande trouvée, comme au traitement.
- **Identifiant HTML en double dans l'espace client** : le lien portait un `id` fixe, alors que le hook d'affichage du compte client est rendu deux fois par page sur Hummingbird (grille du contenu et barre latérale). L'identifiant, référencé nulle part, a été retiré.

## [1.4.9] — 2026-08-26

### Corrigé
- **Lien de rétractation en pied de page invisible pour les visiteurs non connectés** : le lien n'était rendu qu'aux clients connectés, en contradiction avec sa description en configuration (« visible en pied de page de toutes les pages du site ») et avec l'exigence de visibilité de l'ordonnance n°2026-2. Un client passé par la commande invité ne le voyait donc jamais, alors que la page de rétractation gère ce parcours depuis l'origine (recherche de la commande par e-mail + référence). Le lien s'affiche désormais pour tous les visiteurs, connectés ou non, dès que l'option « Afficher le lien en pied de page » est activée. Aucun changement de configuration n'est nécessaire à la mise à jour.

## [1.4.8] — 2026-08-21

### Modifié
- **Licence : GPL v3 vers Open Software License 3.0 (OSL-3.0).** Le cœur de PrestaShop est publié sous OSL-3.0, licence notoirement incompatible avec la GPL quelle que soit sa version. Un module ne pouvant fonctionner sans le cœur, la combinaison des deux ne peut satisfaire les deux copyleft à la fois, ce qui plaçait quiconque redistribue une boutique dans une situation insoluble. L'OSL-3.0 lève l'ambiguïté, aligne le module sur la licence de l'écosystème, et conserve ce qui comptait : l'obligation d'attribution et le partage des modifications. Les versions déjà publiées restent régies par la licence sous laquelle elles ont été distribuées.

## [1.4.7] — 2026-08-21

### Corrigé
- **Erreur 500 sur les filtres de la liste des demandes** : la liste joint les tables `orders` et `customer`, ce qui rendait ambigus les noms de colonnes partagés. Filtrer sur « Référence » ou sur « Demandée le » provoquait une erreur SQL (`Column 'reference'/'date_add' in WHERE is ambiguous`), et la liste restait inaccessible tant que le filtre était mémorisé. Toutes les colonnes issues de la table du module sont désormais explicitement préfixées. Signalé par @PhenixInfo (#9, #10).

### Modifié
- **Libellé du rôle « Expédié » dans le mapping des statuts** : précisé en « Expédié (colis parti, non encore livré) ». Signalé par @alexandrebru83 (#11).
- **Aide de la matrice de mapping** réécrite en liste, et complétée sur le point qui prêtait à confusion : les statuts antérieurs à l'expédition ne se cochent dans aucune colonne, mais **la rétractation y reste possible** (option « Autoriser avant livraison », active par défaut, conformément à l'art. L221-18 dernier alinéa). Le module la traite alors comme une annulation, sans retour de marchandise et avec remboursement intégral.
- **Cohérence d'édition** : le module est désormais publié sous le seul nom d'éditeur **ZM40** (champ auteur PrestaShop, en-tête du fichier principal, panneau de configuration), l'atelier Magic Garden restant mentionné au titre de l'attribution et du copyright.
- **Documentation embarquée** (`docs/documentation.html`) : compatibilité corrigée en PrestaShop 1.7.6 → 9.x (elle annonçait encore 8.x et une version 1.0.0 périmée), et ajout d'une ligne décrivant le cas des statuts laissés non cochés.

## [1.4.6] — 2026-07-16

### Ajouté
- **Bon de retour PDF joint à l'e-mail d'acceptation** : à la validation d'une demande (produit livré ou en cours d'acheminement), un bon de retour au format PDF est généré et joint à l'e-mail. Le message invite explicitement le client à l'imprimer et à le coller sur l'extérieur du colis, condition d'acceptation par le service logistique.
- **Champ « Instructions spécifiques »** en configuration : texte affiché automatiquement dans l'e-mail d'acceptation ET sur le bon de retour PDF (mode d'emballage, numéro RMA à indiquer, transporteur ou point relais imposé…).
- **Photos client : 3 champs séparés** (« Photo 1 », « Photo 2 », « Photo 3 ») dans le formulaire, au lieu d'une sélection multiple obligatoire en une seule fois.

### Modifié
- **Visualisation des photos en back-office** : ouverture dans une fenêtre (lightbox) avec navigation précédente/suivante (souris et clavier) et compteur, au lieu d'un nouvel onglet par photo.
- **Quantité à retourner par défaut à 0** dans le formulaire : le client choisit explicitement les quantités à retourner, ce qui évite de sélectionner par erreur la totalité de la commande.
- **Bouton « Se rétracter »** : reprend désormais les classes de bouton du thème (`btn btn-secondary`) pour un rendu natif sur les thèmes personnalisés, tout en conservant le style par défaut du module.

### Corrigé
- **Compatibilité thème Hummingbird (PS 8.1+/9)** : le lien « Exercer mon droit de rétractation » dans l'espace client utilise désormais le markup du menu compte de Hummingbird (`.account-menu__link`) au lieu de la grille du thème classic, pour un affichage aligné sur les autres liens du menu.
- **Rendu sur les thèmes sans conteneur** : conteneur (`.container`) autour du contenu, alignement du fil d'ariane (breadcrumb) sur le conteneur du thème, style autonome du tableau des commandes éligibles (espacement des colonnes, en-tête, bordures) et espacement du titre de section — pour les thèmes qui ne stylent pas ces éléments.

## [1.4.5] — 2026-07-12

### Corrigé
- **Erreur 500 au dépôt d'une demande après mise à jour** : la colonne `photos` (introduite en 1.4.4) n'était créée qu'à l'installation, jamais lors d'une montée de version PrestaShop — d'où une erreur SQL « Unknown column 'photos' » sur les boutiques ayant migré. Ajout d'un script d'upgrade PrestaShop natif (`upgrade/upgrade-1.4.5.php`) qui crée la colonne pour toutes les mises à jour (idempotent).
- **Délai personnalisé non repris dans le formulaire** : pour une commande non encore livrée, le texte du délai affiché au client était figé à « 14 jours » au lieu de reprendre le délai configuré en back-office. Il utilise désormais la valeur réelle (`RETRACTATION_DELAY_DAYS`). Le cas des commandes livrées (qui affiche une date d'échéance) était déjà correct.

### Sécurité
- **XSS stocké en back-office** : le motif du client et le motif de refus étaient affichés sur la fiche de la demande sans échappement HTML. Un motif saisi côté client pouvait contenir du HTML/JS exécuté à l'ouverture de la fiche par un agent SAV. Les deux champs sont désormais échappés (`|escape:'html':'UTF-8'` avant `nl2br`), sans changement d'affichage pour les contenus légitimes.

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
