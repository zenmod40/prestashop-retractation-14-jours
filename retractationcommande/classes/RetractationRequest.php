<?php
/**
 * Demande de rétractation — entité du module, adossée au retour natif
 * PrestaShop (ps_order_return) créé au moment de la confirmation client.
 *
 * La rétractation peut être partielle (la loi le permet : le consommateur
 * peut ne renvoyer qu'une partie des biens ; les frais de livraison sont
 * alors remboursés au prorata — art. L221-23 / L221-24). Les produits et
 * quantités demandés sont figés dans `products_snapshot` (JSON).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RetractationRequest extends ObjectModel
{
    const STATUS_PENDING = 'pending';     // À vérifier par le SAV
    const STATUS_ACCEPTED = 'accepted';   // Conforme — procédure de retour envoyée
    const STATUS_REFUSED = 'refused';     // Non conforme (hors délai, exclusion légale…)
    const STATUS_REFUNDED = 'refunded';   // Remboursée

    /** @var int */
    public $id_shop;
    /** @var int */
    public $id_order;
    /** @var int */
    public $id_customer;
    /** @var int retour natif lié (0 si désactivé) */
    public $id_order_return;
    /** @var string référence publique non séquentielle (type RMA) — l'id
     *  numérique reste interne au back-office */
    public $reference;
    /** @var string */
    public $status = self::STATUS_PENDING;
    /** @var string|null motif facultatif du client */
    public $message;
    /** @var string|null motif de refus SAV */
    public $refusal_reason;
    /** @var string|null JSON des produits/quantités demandés */
    public $products_snapshot;
    /** @var string|null date de livraison constatée à la demande */
    public $delivery_date;
    /** @var string phase logistique figée au dépôt : delivered / shipped / pending */
    public $shipping_phase = 'pending';
    /** @var string|null date limite légale calculée à la demande */
    public $legal_deadline;
    /** @var int demande déposée dans le délai légal */
    public $within_deadline = 1;
    /** @var string|null fichier PDF de l'accusé de réception */
    public $pdf_filename;
    /** @var string|null JSON des photos jointes par le client (noms de fichiers) */
    public $photos;
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'retractation_request',
        'primary' => 'id_retractation_request',
        'fields' => [
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_order_return' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'reference' => ['type' => self::TYPE_STRING, 'size' => 16],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'message' => ['type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'],
            'refusal_reason' => ['type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'],
            'products_snapshot' => ['type' => self::TYPE_STRING],
            'delivery_date' => ['type' => self::TYPE_DATE, 'allow_null' => true],
            'shipping_phase' => ['type' => self::TYPE_STRING, 'size' => 16],
            'legal_deadline' => ['type' => self::TYPE_DATE, 'allow_null' => true],
            'within_deadline' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'pdf_filename' => ['type' => self::TYPE_STRING, 'size' => 255],
            'photos' => ['type' => self::TYPE_STRING],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Formatage d'un prix compatible PS 1.7.6 → 9.
     *
     * Tools::displayPrice() est déprécié depuis PS 8 (et l'appel avec un objet
     * Currency génère un avertissement) : on passe par le Locale courant, API
     * disponible et recommandée depuis 1.7.6. Repli défensif si le contexte
     * n'expose pas de locale (ne devrait pas arriver en front office).
     */
    public static function formatPrice($price, Currency $currency)
    {
        $context = Context::getContext();
        if (method_exists($context, 'getCurrentLocale') && ($locale = $context->getCurrentLocale())) {
            return $locale->formatPrice((float) $price, $currency->iso_code);
        }

        return number_format((float) $price, 2, ',', ' ') . ' ' . $currency->iso_code;
    }

    /**
     * Référence publique unique, non séquentielle (lettres + chiffres),
     * pour ne pas exposer le volume de rétractations. Format : RET-XXXXXXXX.
     */
    public static function generateReference()
    {
        do {
            $reference = 'RET-' . Tools::strtoupper(Tools::passwdGen(8, 'ALPHANUMERIC'));
            $exists = Db::getInstance()->getValue(
                'SELECT `id_retractation_request` FROM `' . _DB_PREFIX_ . 'retractation_request`
                 WHERE `reference` = \'' . pSQL($reference) . '\''
            );
        } while ($exists);

        return $reference;
    }

    /**
     * Dernière demande active (non refusée) pour une commande.
     *
     * @return array|false ligne SQL ou false
     */
    public static function getByOrder($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'retractation_request`
             WHERE `id_order` = ' . (int) $idOrder . "
               AND `status` != '" . pSQL(self::STATUS_REFUSED) . "'
             ORDER BY `date_add` DESC"
        );
    }

    /**
     * Produits/quantités demandés (snapshot JSON décodé).
     *
     * @param string|null $snapshot
     *
     * @return array [{id_order_detail, product_name, product_reference, quantity}]
     */
    public static function decodeSnapshot($snapshot)
    {
        $data = json_decode((string) $snapshot, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Quantités restant rétractables par ligne de commande :
     * quantité commandée moins quantités déjà engagées dans un retour natif
     * non refusé ou dans une demande active du module sans retour natif lié.
     *
     * @return array<int, int> id_order_detail => quantité restante
     */
    public static function getRemainingQuantities(Order $order)
    {
        $remaining = [];
        foreach ($order->getProducts() as $product) {
            $remaining[(int) $product['id_order_detail']] = (int) $product['product_quantity'];
        }

        // Quantités engagées dans les retours natifs (hors retours refusés).
        $returned = Db::getInstance()->executeS(
            'SELECT ord.`id_order_detail`, SUM(ord.`product_quantity`) AS qty
             FROM `' . _DB_PREFIX_ . 'order_return_detail` ord
             INNER JOIN `' . _DB_PREFIX_ . 'order_return` o
                ON o.`id_order_return` = ord.`id_order_return`
             WHERE o.`id_order` = ' . (int) $order->id . '
               AND o.`state` != ' . (int) RetractationCommande::OR_STATE_DENIED . '
             GROUP BY ord.`id_order_detail`'
        ) ?: [];
        foreach ($returned as $row) {
            $id = (int) $row['id_order_detail'];
            if (isset($remaining[$id])) {
                $remaining[$id] -= (int) $row['qty'];
            }
        }

        // Demandes actives du module sans retour natif (option désactivée).
        $requests = Db::getInstance()->executeS(
            'SELECT `products_snapshot` FROM `' . _DB_PREFIX_ . 'retractation_request`
             WHERE `id_order` = ' . (int) $order->id . "
               AND `status` != '" . pSQL(self::STATUS_REFUSED) . "'
               AND `id_order_return` = 0"
        ) ?: [];
        foreach ($requests as $row) {
            foreach (self::decodeSnapshot($row['products_snapshot']) as $line) {
                $id = (int) ($line['id_order_detail'] ?? 0);
                if (isset($remaining[$id])) {
                    $remaining[$id] -= (int) ($line['quantity'] ?? 0);
                }
            }
        }

        return array_map(static function ($qty) {
            return max(0, (int) $qty);
        }, $remaining);
    }

    /**
     * Date de livraison réellement exploitable ? Les données PrestaShop sont
     * souvent mal formatées : NULL, chaîne vide, '0000-00-00',
     * '0000-00-00 00:00:00' ou valeur invalide => considérée non livrée.
     */
    public static function isRealDeliveryDate($date)
    {
        if (empty($date) || strpos((string) $date, '0000-00-00') === 0) {
            return false;
        }
        $timestamp = strtotime((string) $date);

        return $timestamp !== false && $timestamp > 0 && (int) date('Y', $timestamp) > 1970;
    }

    /**
     * États de commande avec métadonnées pour l'onglet "Mapping des statuts" :
     * id, nom, couleur, drapeaux natifs et nombre de commandes (12 derniers mois).
     *
     * @return array<int, array>
     */
    public static function getOrderStatesWithMeta()
    {
        $idLang = (int) Context::getContext()->language->id;
        $prefix = _DB_PREFIX_;

        $rows = Db::getInstance()->executeS(
            'SELECT os.`id_order_state`, os.`color`, os.`shipped`, os.`delivery`, os.`paid`, os.`logable`, osl.`name`
             FROM `' . $prefix . 'order_state` os
             LEFT JOIN `' . $prefix . 'order_state_lang` osl
                ON osl.`id_order_state` = os.`id_order_state` AND osl.`id_lang` = ' . $idLang . '
             WHERE os.`deleted` = 0
             ORDER BY os.`id_order_state` ASC'
        ) ?: [];

        $since = date('Y-m-d', strtotime('-12 months')) . ' 00:00:00';
        $counts = [];
        $countRows = Db::getInstance()->executeS(
            'SELECT `current_state`, COUNT(*) AS n FROM `' . $prefix . 'orders`
             WHERE `date_add` >= \'' . pSQL($since) . '\' GROUP BY `current_state`'
        ) ?: [];
        foreach ($countRows as $r) {
            $counts[(int) $r['current_state']] = (int) $r['n'];
        }

        $list = [];
        foreach ($rows as $s) {
            $id = (int) $s['id_order_state'];
            $list[] = [
                'id_state' => $id,
                'name' => $s['name'] ?: ('État #' . $id),
                'color' => $s['color'] ?: '#9ca3af',
                'shipped' => (bool) $s['shipped'],
                'delivery' => (bool) $s['delivery'],
                'paid' => (bool) $s['paid'],
                'logable' => (bool) $s['logable'],
                'count' => $counts[$id] ?? 0,
            ];
        }

        return $list;
    }

    /**
     * Détection automatique du mapping (utilisée à l'installation et par le
     * bouton "Remplissage automatique"). Contrairement à un preset d'IDs en
     * dur, on inspecte chaque état réel : drapeaux natifs PrestaShop ET
     * mots-clés du nom (multilingue) — robuste sur les statuts personnalisés.
     *
     * @return array{DELIVERED: int[], BLOCKED: int[]}
     */
    public static function suggestStateMapping()
    {
        $delivered = [];
        $shipped = [];
        $blocked = [];

        // Mots-clés multilingues par rôle.
        $kwDelivered = ['livr', 'deliver', 'entreg', 'conseg', 'gelief', 'zugestellt', 'dostarcz'];
        $kwShipped = ['expédi', 'expedi', 'shipped', 'envoy', 'spedi', 'versand', 'verzonden', 'enviad', 'wysł', 'wysy', 'en transit', 'in transit'];
        $kwBlocked = ['annul', 'rembours', 'cancel', 'refund', 'erreur', 'error', 'litige', 'storn',
            'errore', 'reembols', 'rimbors', 'devol', 'geannul', 'terugbet', 'anulow', 'zwrot', 'błąd', 'fout'];

        foreach (self::getOrderStatesWithMeta() as $s) {
            $id = (int) $s['id_state'];
            $name = Tools::strtolower($s['name']);

            if (self::nameMatches($name, $kwBlocked)) {
                $blocked[] = $id;
                continue; // un état bloquant n'est ni livré ni expédié
            }
            // "Livré" : drapeau natif delivery OU mot-clé de livraison.
            if ($s['delivery'] || self::nameMatches($name, $kwDelivered)) {
                $delivered[] = $id;
                continue;
            }
            // "Expédié / en cours d'acheminement" : drapeau natif shipped OU mot-clé.
            if ($s['shipped'] || self::nameMatches($name, $kwShipped)) {
                $shipped[] = $id;
            }
        }

        // Filet de sécurité : états natifs annulé / remboursé / erreur.
        foreach ([(int) Configuration::get('PS_OS_CANCELED'), (int) Configuration::get('PS_OS_REFUND'), (int) Configuration::get('PS_OS_ERROR')] as $id) {
            if ($id && !in_array($id, $blocked, true)) {
                $blocked[] = $id;
            }
        }
        $idDelivered = (int) Configuration::get('PS_OS_DELIVERED');
        if ($idDelivered && !in_array($idDelivered, $delivered, true)) {
            $delivered[] = $idDelivered;
        }
        $idShipped = (int) Configuration::get('PS_OS_SHIPPING');
        if ($idShipped && !in_array($idShipped, $delivered, true) && !in_array($idShipped, $blocked, true) && !in_array($idShipped, $shipped, true)) {
            $shipped[] = $idShipped;
        }

        // Exclusivité : bloquant > livré > expédié.
        $delivered = array_values(array_diff($delivered, $blocked));
        $shipped = array_values(array_diff($shipped, $blocked, $delivered));

        return ['DELIVERED' => $delivered, 'SHIPPED' => $shipped, 'BLOCKED' => $blocked];
    }

    protected static function nameMatches($haystackLower, array $needles)
    {
        foreach ($needles as $n) {
            if ($n !== '' && strpos($haystackLower, $n) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liste d'IDs d'états mappés sur un rôle (clé de configuration CSV).
     *
     * @return int[]
     */
    public static function getMappedStates($configKey)
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Configuration::get($configKey)))));
    }

    /**
     * États bloquants : les 3 états natifs (annulé / remboursé / erreur) +
     * ceux mappés manuellement par le marchand (onglet "Mapping des statuts").
     *
     * @return int[]
     */
    public static function getBlockedStates()
    {
        $default = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'),
            (int) Configuration::get('PS_OS_ERROR'),
        ]);

        return array_values(array_unique(array_merge($default, self::getMappedStates('RETRACTATION_BLOCKED_STATES'))));
    }

    /**
     * Date de livraison effective servant de départ au délai de 14 jours :
     *  1) la date native delivery_date (référence légale), si présente ;
     *  2) sinon, la date d'entrée dans un état mappé "Livré" (order_history),
     *     pour les boutiques dont l'état "livré" n'a pas le drapeau natif.
     *
     * @return string|null date Y-m-d H:i:s ou null si non livrée
     */
    public static function getEffectiveDeliveryDate(Order $order)
    {
        if (self::isRealDeliveryDate($order->delivery_date)) {
            return $order->delivery_date;
        }

        $deliveredStates = self::getMappedStates('RETRACTATION_DELIVERED_STATES');
        if ($deliveredStates) {
            $date = Db::getInstance()->getValue(
                'SELECT MIN(`date_add`) FROM `' . _DB_PREFIX_ . 'order_history`
                 WHERE `id_order` = ' . (int) $order->id . '
                   AND `id_order_state` IN (' . implode(',', array_map('intval', $deliveredStates)) . ')'
            );
            if (self::isRealDeliveryDate($date)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * La commande est-elle expédiée / en cours d'acheminement (mais pas
     * encore livrée) ? Détection par les états mappés "Expédié" ET par le
     * drapeau natif `shipped` rencontré dans l'historique de la commande.
     */
    public static function isShipped(Order $order)
    {
        $shippedStates = self::getMappedStates('RETRACTATION_SHIPPED_STATES');
        $conditions = ['os.`shipped` = 1'];
        if ($shippedStates) {
            $conditions[] = 'oh.`id_order_state` IN (' . implode(',', array_map('intval', $shippedStates)) . ')';
        }

        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` oh
             INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = oh.`id_order_state`
             WHERE oh.`id_order` = ' . (int) $order->id . ' AND (' . implode(' OR ', $conditions) . ')'
        );

        return $count > 0;
    }

    /**
     * Phase logistique au sens du module : 'delivered' (délai 14 j en cours),
     * 'shipped' (colis parti, non encore livré) ou 'pending' (non expédié).
     */
    public static function getShippingPhase(Order $order)
    {
        if (self::getEffectiveDeliveryDate($order)) {
            return 'delivered';
        }

        return self::isShipped($order) ? 'shipped' : 'pending';
    }

    /**
     * Éligibilité d'une commande au bouton "Se rétracter".
     *
     * Le bouton est affiché uniquement pendant la fenêtre légale et tant
     * qu'il reste des quantités rétractables ; la vérification fine
     * (exclusions L221-28, état du produit…) reste à la main du SAV.
     *
     * @return array{eligible: bool, delivered: bool, delivery_date: ?string, deadline: ?DateTime, deadline_text: string, reason: string, excluded_products: array, remaining: array}
     */
    public static function getOrderEligibility(Order $order)
    {
        $result = [
            'eligible' => false,
            'delivered' => false,
            'delivery_date' => null,
            'shipping_phase' => 'pending',
            'deadline' => null,
            'deadline_text' => '',
            'reason' => '',
            'excluded_products' => [],
            'remaining' => [],
        ];

        if (!$order->valid && !count($order->getHistory((int) Context::getContext()->language->id))) {
            $result['reason'] = 'invalid_order';

            return $result;
        }

        // États bloquants (annulé / remboursé / erreur + mapping marchand) : pas de bouton.
        $currentState = (int) $order->getCurrentState();
        if (in_array($currentState, self::getBlockedStates(), true)) {
            $result['reason'] = 'order_state';

            return $result;
        }

        // Plus aucune quantité rétractable (tout est déjà en cours de
        // rétractation/retour) : pas de nouveau dépôt possible.
        $remaining = self::getRemainingQuantities($order);
        $result['remaining'] = $remaining;
        if (!array_sum($remaining)) {
            $result['reason'] = 'nothing_returnable';

            return $result;
        }

        // Exclusions légales configurées (art. L221-28) : bouton masqué
        // uniquement si TOUTE la commande est exclue.
        $excluded = self::getExcludedProducts($order);
        $result['excluded_products'] = $excluded;
        if (count($excluded) && count($excluded) >= count($order->getProducts())) {
            $result['reason'] = 'all_products_excluded';

            return $result;
        }

        $deliveryDate = self::getEffectiveDeliveryDate($order);
        $delivered = (bool) $deliveryDate;
        $result['delivered'] = $delivered;
        $result['delivery_date'] = $deliveryDate;
        $result['shipping_phase'] = $delivered ? 'delivered' : (self::isShipped($order) ? 'shipped' : 'pending');

        if ($delivered) {
            $deadline = RetractationDelai::getDeadline($deliveryDate);
            $result['deadline'] = $deadline;
            $result['deadline_text'] = sprintf(
                Context::getContext()->getTranslator()->trans('jusqu\'au %s inclus', [], 'Modules.Retractationcommande.Shop'),
                Tools::displayDate($deadline->format('Y-m-d'))
            );
            if (new DateTime() > $deadline) {
                $result['reason'] = 'deadline_passed';

                return $result;
            }
        } else {
            // Pas encore livrée : droit exerçable dès la conclusion du contrat.
            if (!Configuration::get('RETRACTATION_ALLOW_UNDELIVERED')) {
                $result['reason'] = 'not_delivered';

                return $result;
            }
            $result['deadline_text'] = Context::getContext()->getTranslator()->trans(
                'commande non encore livrée — délai de 14 jours à compter du lendemain de la livraison',
                [],
                'Modules.Retractationcommande.Shop'
            );
        }

        $result['eligible'] = true;

        return $result;
    }

    /**
     * Produits de la commande visés par une exclusion configurée
     * (produit listé ou appartenant à une catégorie exclue).
     *
     * @return array liste de lignes produit de $order->getProducts()
     */
    public static function getExcludedProducts(Order $order)
    {
        $excludedProducts = array_filter(array_map('intval', explode(',', (string) Configuration::get('RETRACTATION_EXCLUDED_PRODUCTS'))));
        $excludedCats = array_filter(array_map('intval', explode(',', (string) Configuration::get('RETRACTATION_EXCLUDED_CATS'))));

        if (!$excludedProducts && !$excludedCats) {
            return [];
        }

        $excluded = [];
        foreach ($order->getProducts() as $product) {
            $idProduct = (int) $product['product_id'];
            if (in_array($idProduct, $excludedProducts, true)) {
                $excluded[] = $product;
                continue;
            }
            if ($excludedCats) {
                $productCats = array_map('intval', Product::getProductCategories($idProduct));
                if (array_intersect($productCats, $excludedCats)) {
                    $excluded[] = $product;
                }
            }
        }

        return $excluded;
    }

    /**
     * Crée le retour natif PrestaShop avec les lignes/quantités demandées
     * par le client et le lie à la demande.
     *
     * @param array<int, int> $selection id_order_detail => quantité
     *
     * @return int id du retour natif créé, 0 si rien à retourner
     */
    public function createNativeOrderReturn(Order $order, array $selection)
    {
        $orderDetailList = [];
        $productQtyList = [];
        foreach ($selection as $idOrderDetail => $qty) {
            if ((int) $qty > 0) {
                $orderDetailList[] = (int) $idOrderDetail;
                $productQtyList[] = (int) $qty;
            }
        }
        if (!$orderDetailList) {
            return 0;
        }

        $orderReturn = new OrderReturn();
        $orderReturn->id_customer = (int) $order->id_customer;
        $orderReturn->id_order = (int) $order->id;
        $orderReturn->state = RetractationCommande::OR_STATE_WAITING_CONFIRMATION;
        $orderReturn->question = 'Rétractation légale (art. L221-18 C. conso) — demande déposée le '
            . date('d/m/Y') . ' via l\'espace client.'
            . ($this->message ? '<br>Motif du client : ' . $this->message : '');
        $orderReturn->add();
        $orderReturn->addReturnDetail($orderDetailList, $productQtyList, [], []);

        $this->id_order_return = (int) $orderReturn->id;

        return (int) $orderReturn->id;
    }

    /**
     * Met à jour l'état du retour natif lié (workflow SAV).
     */
    public function setNativeReturnState($state)
    {
        if (!$this->id_order_return) {
            return;
        }
        $orderReturn = new OrderReturn((int) $this->id_order_return);
        if (Validate::isLoadedObject($orderReturn)) {
            $orderReturn->state = (int) $state;
            $orderReturn->update();
        }
    }
}
