<?php
/**
 * BO SAV > Rétractations : liste des demandes, vérification d'éligibilité,
 * validation (envoi de la procédure de retour), refus, remboursement.
 * Synchronise l'état du retour natif PrestaShop lié.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationDelai.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationRequest.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationPdf.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationPhoto.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationWorkflow.php';

class AdminRetractationController extends ModuleAdminController
{
    /**
     * Compat traduction cross-version : PrestaShop 9 a retiré la méthode legacy
     * l() des contrôleurs admin (UndefinedMethodError). On délègue au natif sur
     * 1.7/8, sinon à Module::l() (repli ultime : chaîne source).
     */
    public function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        if (method_exists(get_parent_class($this), 'l')) {
            return parent::l($string, $class, $addslashes, $htmlentities);
        }
        if (isset($this->module) && $this->module instanceof Module) {
            return $this->module->l($string, 'adminretractationcontroller');
        }

        return $string;
    }

    public function __construct()
    {
        $this->table = 'retractation_request';
        $this->className = 'RetractationRequest';
        $this->identifier = 'id_retractation_request';
        $this->bootstrap = true;
        $this->list_no_link = false;
        $this->allow_export = true;
        $this->_orderBy = 'date_add';
        $this->_orderWay = 'DESC';

        parent::__construct();

        $this->_select = 'o.reference AS order_reference, CONCAT(c.firstname, " ", c.lastname) AS customer_name, c.email AS customer_email';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.`id_order` = a.`id_order`)
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = a.`id_customer`)';

        $this->fields_list = [
            'id_retractation_request' => ['title' => 'ID', 'align' => 'center', 'class' => 'fixed-width-xs'],
            // La liste joint `orders` et `customer` : toute colonne de la table du module
            // doit être préfixée via filter_key, sinon le filtre produit un WHERE ambigu
            // (erreur SQL 1052) sur les noms partagés (reference, date_add).
            'reference' => [
                'title' => $this->l('Référence'),
                'class' => 'fixed-width-sm',
                'filter_key' => 'a!reference',
            ],
            'order_reference' => ['title' => $this->l('Commande'), 'havingFilter' => true],
            'customer_name' => ['title' => $this->l('Client'), 'havingFilter' => true],
            'customer_email' => ['title' => $this->l('Email'), 'havingFilter' => true],
            'date_add' => ['title' => $this->l('Demandée le'), 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'legal_deadline' => ['title' => $this->l('Date limite légale'), 'type' => 'datetime', 'filter_key' => 'a!legal_deadline'],
            'within_deadline' => [
                'title' => $this->l('Dans les délais'),
                'align' => 'center',
                'type' => 'bool',
                'callback' => 'displayWithinDeadline',
                'filter_key' => 'a!within_deadline',
            ],
            'status' => [
                'title' => $this->l('Statut'),
                'align' => 'center',
                'type' => 'select',
                'list' => self::getStatusLabels(),
                'filter_key' => 'a!status',
                'callback' => 'displayStatus',
            ],
        ];

        $this->actions = ['view'];
    }

    public static function getStatusLabels()
    {
        return [
            RetractationRequest::STATUS_PENDING => 'À vérifier',
            RetractationRequest::STATUS_ACCEPTED => 'Conforme — procédure envoyée',
            RetractationRequest::STATUS_REFUSED => 'Refusée',
            RetractationRequest::STATUS_REFUNDED => 'Remboursée',
        ];
    }

    public function displayStatus($value)
    {
        $labels = self::getStatusLabels();
        $classes = [
            RetractationRequest::STATUS_PENDING => 'badge-warning',
            RetractationRequest::STATUS_ACCEPTED => 'badge-info',
            RetractationRequest::STATUS_REFUSED => 'badge-danger',
            RetractationRequest::STATUS_REFUNDED => 'badge-success',
        ];

        return '<span class="badge ' . ($classes[$value] ?? 'badge-default') . '">' . ($labels[$value] ?? $value) . '</span>';
    }

    public function displayWithinDeadline($value)
    {
        return $value
            ? '<span class="badge badge-success">' . $this->l('Oui') . '</span>'
            : '<span class="badge badge-danger">' . $this->l('Non') . '</span>';
    }

    /* ------------------------------------------------------------------ */
    /* Vue détaillée                                                       */
    /* ------------------------------------------------------------------ */

    public function renderView()
    {
        $request = $this->loadObject();
        if (!Validate::isLoadedObject($request)) {
            $this->errors[] = $this->l('Demande introuvable.');

            return '';
        }

        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);
        $products = Validate::isLoadedObject($order) ? $order->getProducts() : [];
        $excluded = Validate::isLoadedObject($order) ? RetractationRequest::getExcludedProducts($order) : [];
        $excludedIds = array_map(static function ($p) {
            return (int) $p['id_order_detail'];
        }, $excluded);

        $orderReturnLink = null;
        if ($request->id_order_return) {
            $orderReturnLink = $this->context->link->getAdminLink('AdminReturn', true, [], [
                'id_order_return' => (int) $request->id_order_return,
                'updateorder_return' => 1,
            ]);
        }

        // Quantités demandées par le client (snapshot figé au dépôt).
        $requestedQty = [];
        foreach (RetractationRequest::decodeSnapshot($request->products_snapshot) as $line) {
            $requestedQty[(int) ($line['id_order_detail'] ?? 0)] = (int) ($line['quantity'] ?? 0);
        }

        // Photos jointes par le client : URLs servies par ce contrôleur (token).
        $rcPhotos = [];
        $photoNames = json_decode((string) $request->photos, true);
        if (is_array($photoNames)) {
            foreach ($photoNames as $pn) {
                $pn = basename((string) $pn);
                if ($pn !== '' && RetractationPhoto::getPath($pn)) {
                    $rcPhotos[] = self::$currentIndex . '&id_retractation_request=' . (int) $request->id
                        . '&downloadRetractationPhoto&photo=' . urlencode($pn)
                        . '&token=' . $this->token;
                }
            }
        }

        $this->context->smarty->assign([
            'rc_request' => $request,
            'rc_photos' => $rcPhotos,
            'rc_status_labels' => self::getStatusLabels(),
            'rc_order' => Validate::isLoadedObject($order) ? $order : null,
            'rc_customer' => Validate::isLoadedObject($customer) ? $customer : null,
            'rc_products' => $products,
            'rc_requested_qty' => $requestedQty,
            'rc_excluded_ids' => $excludedIds,
            'rc_order_link' => Validate::isLoadedObject($order)
                ? $this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => (int) $order->id, 'vieworder' => 1])
                : null,
            'rc_order_return_link' => $orderReturnLink,
            'rc_pdf_available' => (bool) RetractationPdf::getPath($request->pdf_filename),
            'rc_current_index' => self::$currentIndex . '&id_retractation_request=' . (int) $request->id
                . '&viewretractation_request&token=' . $this->token,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'retractationcommande/views/templates/admin/view.tpl'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Actions SAV                                                         */
    /* ------------------------------------------------------------------ */

    public function postProcess()
    {
        if (Tools::isSubmit('submitAcceptRetractation')) {
            $this->processAccept();
        } elseif (Tools::isSubmit('submitRefuseRetractation')) {
            $this->processRefuse();
        } elseif (Tools::isSubmit('submitRefundRetractation')) {
            $this->processRefund();
        } elseif (Tools::isSubmit('downloadRetractationPdf')) {
            $this->processDownloadPdf();
        } elseif (Tools::isSubmit('downloadRetractationPhoto')) {
            $this->processDownloadPhoto();
        }

        return parent::postProcess();
    }

    protected function loadRequestOrFail()
    {
        $request = new RetractationRequest((int) Tools::getValue('id_retractation_request'));
        if (!Validate::isLoadedObject($request)) {
            $this->errors[] = $this->l('Demande introuvable.');

            return null;
        }

        return $request;
    }

    /**
     * Demande conforme.
     * - Commande livrée au moment de la demande : envoi de la procédure de
     *   retour, retour natif passé à "En attente du colis".
     * - Commande non expédiée : annulation avant expédition — aucun retour
     *   de produit, email dédié (annuler l'expédition puis rembourser).
     */
    protected function processAccept()
    {
        $request = $this->loadRequestOrFail();
        if (!$request || !$this->runWorkflow('accept', $request)) {
            return;
        }

        $phase = $request->shipping_phase ?: ($request->delivery_date ? 'delivered' : 'pending');
        if ($phase === 'pending') {
            $this->confirmations[] = $this->l('Demande validée (commande non expédiée) : le client a été informé de l\'annulation. Pensez à annuler l\'expédition et à effectuer le remboursement depuis la fiche commande, puis marquez la demande comme remboursée.');
        } else {
            $this->confirmations[] = ($phase === 'shipped')
                ? $this->l('Demande validée (commande en cours d\'acheminement) : la procédure de retour a été envoyée au client. Il pourra refuser le colis ou le renvoyer.')
                : $this->l('Demande validée : la procédure de retour a été envoyée au client.');
        }
    }

    /**
     * Demande non conforme (hors délai, exclusion légale…).
     */
    protected function processRefuse()
    {
        $request = $this->loadRequestOrFail();
        if ($request && $this->runWorkflow('refuse', $request, Tools::getValue('refusal_reason'))) {
            $this->confirmations[] = $this->l('Demande refusée : le client a été informé du motif.');
        }
    }

    /**
     * Produit reçu, contrôlé et remboursé. Le remboursement lui-même se fait
     * via la commande (remboursement standard/partiel natif).
     */
    protected function processRefund()
    {
        $request = $this->loadRequestOrFail();
        if ($request && $this->runWorkflow('markRefunded', $request)) {
            $this->confirmations[] = $this->l('Demande marquée comme remboursée, le client a été informé. Pensez à effectuer le remboursement réel depuis la fiche commande si ce n\'est pas déjà fait.');
        }
    }

    /**
     * Exécute un geste de RetractationWorkflow et traduit ses codes en messages.
     *
     * @return bool true si l'état a changé (même avec avertissements)
     */
    protected function runWorkflow($action, RetractationRequest $request, $reason = null)
    {
        $idEmployee = (int) $this->context->employee->id;
        try {
            $result = ($action === 'refuse')
                ? RetractationWorkflow::refuse($request, $reason, $idEmployee, 'bo')
                : RetractationWorkflow::$action($request, $idEmployee, 'bo');
        } catch (RetractationWorkflowException $e) {
            $messages = [
                'bad_status' => $this->l('Cette demande a déjà été traitée : aucune action effectuée, aucun e-mail envoyé.'),
                'reason_required' => $this->l('Merci d\'indiquer le motif du refus (il sera communiqué au client).'),
                'reason_invalid' => $this->l('Le motif contient des caractères non autorisés.'),
                'save_failed' => $this->l('Impossible d\'enregistrer le nouvel état : aucun e-mail envoyé.'),
            ];
            $this->errors[] = isset($messages[$e->getMessage()]) ? $messages[$e->getMessage()] : $e->getMessage();

            return false;
        }

        if (in_array('pdf_failed', $result['warnings'], true)) {
            $this->warnings[] = $this->l('Le bon de retour PDF n\'a pas pu être généré : l\'e-mail est parti sans pièce jointe.');
        }
        if (in_array('mail_failed', $result['warnings'], true)) {
            $this->warnings[] = $this->l('L\'état a été enregistré, mais l\'e-mail au client n\'a pas pu être envoyé.');
        }

        return true;
    }

    protected function processDownloadPdf()
    {
        $request = $this->loadRequestOrFail();
        $path = $request ? RetractationPdf::getPath($request->pdf_filename) : null;
        if (!$path) {
            $this->errors[] = $this->l('PDF introuvable.');

            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    protected function processDownloadPhoto()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }
        // Sécurité : la photo demandée doit appartenir à CETTE demande.
        $requested = basename((string) Tools::getValue('photo'));
        $allowed = json_decode((string) $request->photos, true);
        if (!is_array($allowed) || !in_array($requested, array_map('basename', $allowed), true)) {
            $this->errors[] = $this->l('Photo introuvable.');

            return;
        }
        $path = RetractationPhoto::getPath($requested);
        if (!$path) {
            $this->errors[] = $this->l('Photo introuvable.');

            return;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
