# Rétractation Commande — Module PrestaShop 1.7 / 8 / 9

Module de mise en conformité avec le droit de rétractation (art. L221-18 et suivants du
Code de la consommation — réf. [service-public.gouv.fr F10485](https://www.service-public.gouv.fr/particuliers/vosdroits/F10485))
et avec l'**ordonnance n°2026-2 / décret n°2026-3** (fonctionnalité de rétractation en ligne
gratuite, visible et facilement accessible), applicables au **19 juin 2026**.

**Compatibilité :** PrestaShop **1.7.6 → 9.x** · PHP 7.2 → 8.3.

## Nouveautés v1.3.1 — Mapping des statuts + parcours « en transit »

- **Onglet « Mapping des statuts »** dans la configuration (façon CoolStats) : une matrice interactive associe chaque statut de commande à un rôle —
  **« Livré »** (démarre le délai légal de 14 jours, en complément du drapeau natif PrestaShop), **« Expédié (en cours d'acheminement) »** (colis parti mais non encore livré) et **« Bloquant »** (masque le bouton, en plus des états annulé/remboursé/erreur toujours bloquants).
- **3 situations gérées** avec textes adaptés (modal, accusé PDF, emails, écran SAV) : commande **non expédiée** (annulation, rien à renvoyer), **en transit** (« refusez le colis ou suivez la procédure de retour ») et **livrée** (retour produit). Phase logistique figée au dépôt (`shipping_phase`) et affichée au SAV.
- **Remplissage automatique dynamique** : au lieu d'un preset d'IDs en dur, le module détecte chaque statut réel via ses *drapeaux* PrestaShop (`shipped`, `delivery`, `paid`) **et** des mots-clés multilingues du nom (livré, expédié, annulé, remboursé, delivered, shipped, cancel…). Pré-rempli dès l'installation.
- Matrice avec pastille couleur, nombre de commandes sur 12 mois, recherche, résumé live et détection de conflits (un statut = un seul rôle).
- Le délai de 14 jours peut démarrer sur un état « livré » mappé, via la date d'entrée dans cet état (`order_history`) — utile pour les boutiques dont l'état livré n'a pas le drapeau natif. Nouvelles chaînes traduites dans les 8 langues. Rétrocompatible.

## Nouveautés v1.3

- **Compatibilité PrestaShop 9** (déclaration de compatibilité étendue à 9.x)
- Suppression des appels dépréciés : formatage des prix via `Locale::formatPrice()` (au lieu de `Tools::displayPrice`), date du formulaire générée côté PHP (plus de `strftime` déprécié en PHP 8.1)

## Nouveautés v1.2 — multilingue (8 langues)

- **Interface front traduite** (`translations/<iso>.php`, 116 chaînes par langue) : modal, page « Exercer mon droit », accusé PDF, page 2 « Rappel de vos droits », encart détail de commande, messages serveur. Clés MD5 PrestaShop générées par script depuis les chaînes réelles (clés exactes garanties).
- **Emails traduits** (`mails/<iso>/`) : les 6 emails (accusé, notif SAV, procédure, refus, annulation, remboursée) en **HTML + texte**, logo et variables identiques au français.
- **Clause CGV en 8 langues** dans `getCgvClause()` ; le panneau de config affiche une clause **par langue installée** dans la boutique (repli FR sinon).
- **7 langues livrées** : FR (source) + EN, ES, DE, IT, NL, PT, PL.
- **Format 100 % natif PrestaShop** : emails éditables via *International > Traductions > Traductions des e-mails*, interface via *Traductions des modules installés*.
- **Sélection automatique** de la langue (commande/navigation du client) ; repli sur l'anglais (emails) ou le français (interface).
- **Terminologie juridique adaptée** par langue (Widerruf, recesso, herroeping, desistimiento, livre resolução, odstąpienie) ; les références d'articles (L221-x) restent en français (droit français cité).

## Nouveautés v1.1

- **Lien footer** « Exercer mon droit de rétractation » sur toutes les pages (libellé personnalisable, activable/désactivable)
- **Page dédiée** avec **parcours invité** (email + référence de commande)
- **Rétractation partielle** : sélection des produits et quantités dans la modal (frais de livraison au prorata)
- **Référence non séquentielle type RMA** (RET-XXXXXXXX) — l'ID numérique reste interne au BO
- **PDF enrichi** : logo boutique, bloc « Boutique : », références produits, prix TTC, sous-total, livraison, montant à rembourser
- **Emails** : logo boutique en en-tête ({shop_logo}), email « rétractation remboursée », fallback de langue
- **Clause CGV bilingue** prête à copier dans la configuration (obligation d'information de l'ordonnance)
- **Délai configurable** (14 jours minimum légal, extensible)
- **Masquage du formulaire de retour natif** (option, activée par défaut) — un seul point d'entrée
- Badge de statut sur la ligne de commande (« en cours de vérification » / « validée » / « remboursée »)
- Correctif : bouton injecté une seule fois par ligne dans « Mes commandes », style bouton visible
- Multilingue : front + PDF via {l} (BO > Traductions), emails FR/EN

Documentation complète : [docs/documentation.html](retractationcommande/docs/documentation.html)

## 1. Analyse du système natif PS8 (point de départ)

PrestaShop 8 possède un système de retours produits natif sur lequel le module s'appuie :

| Élément natif | Rôle |
|---|---|
| Tables `order_return` / `order_return_detail` | Retour (client, commande, état, message) + lignes produits |
| États `order_return_state` (1→5) | En attente de confirmation → En attente du colis → Colis reçu → Refusé → Terminé |
| BO **SAV > Retours produits** (`AdminReturn`) | Gestion des retours par le SAV |
| FO « Mes retours de marchandise » (`order-follow`) | Suivi des retours par le client |
| Config `PS_ORDER_RETURN` / `PS_ORDER_RETURN_NB_DAYS` | Activation + fenêtre de retour native |

**Limites du natif au regard de la loi** (d'où ce module) :
- le décompte natif part du jour de livraison, pas du **lendemain** ;
- pas de **prolongation au 1er jour ouvrable** si l'échéance tombe un samedi, dimanche ou jour férié ;
- pas de formulaire type de rétractation (annexe art. R221-1) ;
- pas d'**accusé de réception** (obligatoire quand la demande passe par le site — art. L221-21 al. 3) ;
- pas de demande possible **avant livraison** (droit ouvert dès la conclusion du contrat — art. L221-18) ;
- le bouton natif se trouve dans le détail de commande, pas dans la liste « Mes commandes ».

Le module **crée un retour natif** pour chaque rétractation confirmée : le SAV retrouve ses
outils habituels et le client suit son retour dans son espace client comme d'habitude.

## 2. Parcours implémenté

1. **Espace client > Mes commandes** : bouton **« Se rétracter »** sur chaque commande,
   affiché *uniquement pendant la fenêtre légale* (aussi sur le détail de commande).
2. Clic → **modal** avec le **formulaire type de rétractation prérempli**
   (annexe R221-1 : coordonnées, commande, produits, dates) + motif facultatif.
3. **« Confirmer la rétractation »** → en une transaction :
   - demande enregistrée (`retractation_request`) avec la date limite légale calculée et figée ;
   - **retour natif** créé (état « En attente de confirmation ») avec toutes les lignes non déjà retournées ;
   - **accusé de réception PDF** généré (TCPDF) + envoyé par **email** au client (PDF joint) ;
   - **notification email au SAV** avec les points de contrôle.
4. **BO SAV > Rétractations** : vérification de l'éligibilité (délai, exclusions L221-28
   signalées, lien commande + retour natif), puis :
   - **Conforme** → email « procédure de retour » (texte configurable) + retour natif → « En attente du colis » ;
   - **Refuser** (motif obligatoire, communiqué au client) → retour natif → « Refusé » ;
   - après réception et contrôle → **Marquer remboursée** → retour natif → « Terminé »
     (le remboursement réel se fait depuis la fiche commande, comme d'habitude).

## 3. Calcul du délai légal (`classes/RetractationDelai.php`)

- 14 jours calendaires, décompte démarrant **le lendemain de la livraison**
  (`delivery_date` de la commande, posée par l'état logistique « Livré ») ⇒ dernier jour = livraison + 14, jusqu'à 23:59:59 ;
- si le dernier jour tombe un **samedi, dimanche ou jour férié français**, prolongation
  jusqu'au **premier jour ouvrable suivant** (jours fériés fixes + Pâques/Ascension/Pentecôte
  calculés par l'algorithme de Meeus/Butcher — testé) ;
- commande **non encore livrée** : bouton affiché (droit exerçable dès la conclusion du
  contrat — désactivable dans la config) ;
- la date limite est **figée dans la demande** au moment du dépôt (preuve en cas de litige).

> Jours fériés d'Alsace-Moselle (26/12, Vendredi saint) non inclus — à ajouter dans
> `RetractationDelai::getFrenchHolidays()` si nécessaire.

## 4. Conformité — correspondance loi ↔ module

| Exigence légale | Implémentation |
|---|---|
| Délai 14 jours, départ le lendemain de la livraison (L221-18) | `RetractationDelai::getDeadline()` |
| Prolongation si échéance samedi/dimanche/férié | idem, jours fériés FR calculés |
| Droit exerçable dès la conclusion du contrat | bouton visible avant livraison (option) |
| Formulaire type (R221-1) | modal préremplie (vendeur, commande, biens, consommateur, date) |
| Pas d'obligation de motiver | champ motif **facultatif**, mention explicite |
| Accusé de réception si dépôt via le site (L221-21 al. 3) | PDF généré + email immédiat |
| Exclusions (L221-28) | catégories/produits exclus configurables : bouton masqué si toute la commande est exclue, sinon alerte SAV ; vérification humaine SAV (process voulu) |
| Remboursement ≤ 14 jours après récupération/preuve d'expédition (L221-24), frais de renvoi au client (L221-23) | rappelé dans l'accusé, l'email et l'écran SAV |

## 5. Installation

Récupérez le **ZIP prêt à installer**, au choix :

- **Tout public** → [magicgarden.fr/retractation.php](https://magicgarden.fr/retractation.php) (version testée et tenue à jour).
- **Développeurs** → la dernière [**Release GitHub**](https://github.com/zenmod40/prestashop-retractation-14-jours/releases) (ZIP attaché à chaque version).

Puis :

1. Back-office PrestaShop → **Modules → Téléverser un module** → déposer le ZIP (ou décompresser dans `/modules/` puis installer).
2. Configurer : email SAV, texte de la procédure de retour, exclusions éventuelles (IDs
   produits/catégories au titre de L221-28 : sur mesure, périssables, hygiène descellée…),
   puis copier la **clause CGV** générée dans vos conditions générales de vente.
3. Recommandé : laisser **`PS_ORDER_RETURN` activé** (Service client > Retours produits)
   pour que le client suive ses retours dans « Mes retours de marchandise ».
4. Vérifier que les **états de commande logistiques « Livré »** ont bien la case
   « Considérer l'état associé comme livré » cochée : c'est elle qui pose `delivery_date`,
   point de départ du décompte.

> **Depuis les sources** (contributeurs) : clonez le dépôt et zippez le dossier `retractationcommande/` — c'est ce dossier, et lui seul, qui s'installe dans PrestaShop. Le ZIP des Releases est exactement ce dossier packagé.

## 6. Structure

```
retractationcommande/
├── retractationcommande.php                  # module : hooks FO, config BO, install (table + onglet SAV), clause CGV 8 langues
├── classes/
│   ├── RetractationDelai.php                 # délai légal (14j + lendemain + fériés FR) — testé
│   ├── RetractationRequest.php               # entité demande + éligibilité + pont vers OrderReturn natif
│   └── RetractationPdf.php                   # accusé de réception PDF (TCPDF, stockage protégé)
├── controllers/
│   ├── front/demande.php                     # modal (form), confirmation, téléchargement PDF
│   ├── front/formulaire.php                  # page « Exercer mon droit » + parcours invité
│   └── admin/AdminRetractationController.php # BO SAV > Rétractations (liste, vue, actions)
├── views/                                    # js modal + css + templates front/hook/admin + PDF
├── translations/                             # interface front : en, es, de, it, nl, pl, pt (FR = source via {l})
└── mails/                                    # 6 emails (HTML + txt) × 8 langues : fr, en, es, de, it, nl, pl, pt
```

## Auteur

Développé par **[Magic Garden](https://magicgarden.fr)** — création de sites & e-commerce, hébergement infogéré, intégrations et modules PrestaShop sur mesure.

- 🌱 Site : https://magicgarden.fr
- 🛠️ Besoin d'une installation, d'une personnalisation ou d'une garantie de conformité ? → [Contact](https://magicgarden.fr)

## Licence

Distribué sous licence **[MIT](LICENSE)** : libre d'utilisation, de modification et de redistribution, y compris à titre commercial, **à condition de conserver la mention d'auteur et de licence**. Les forks et adaptations sont les bienvenus.

## Contribuer

Les *issues* et *pull requests* sont bienvenues (bugs, traductions, compatibilité). Merci de décrire votre version de PrestaShop et de PHP.

## ⚠️ Avertissement juridique

Ce module est une **aide à la mise en conformité** avec le droit de rétractation (art. L221-18 et suivants du Code de la consommation) et avec l'ordonnance n°2026-2. **Il ne constitue pas un conseil juridique** : le marchand reste seul responsable de la conformité de sa boutique, notamment de l'adaptation des clauses CGV et de la gestion des exceptions de l'article L221-28. Fourni « en l'état » (*as-is*), sans support inclus.
