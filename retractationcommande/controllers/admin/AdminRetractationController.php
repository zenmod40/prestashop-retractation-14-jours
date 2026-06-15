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

class AdminRetractationController extends ModuleAdminController
{
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
            'reference' => ['title' => $this->l('Référence'), 'class' => 'fixed-width-sm'],
            'order_reference' => ['title' => $this->l('Commande'), 'havingFilter' => true],
            'customer_name' => ['title' => $this->l('Client'), 'havingFilter' => true],
            'customer_email' => ['title' => $this->l('Email'), 'havingFilter' => true],
            'date_add' => ['title' => $this->l('Demandée le'), 'type' => 'datetime'],
            'legal_deadline' => ['title' => $this->l('Date limite légale'), 'type' => 'datetime'],
            'within_deadline' => [
                'title' => $this->l('Dans les délais'),
                'align' => 'center',
                'type' => 'bool',
                'callback' => 'displayWithinDeadline',
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

        $this->context->smarty->assign([
            'rc_request' => $request,
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
        if (!$request) {
            return;
        }

        $request->status = RetractationRequest::STATUS_ACCEPTED;
        $request->update();

        // Phase figée au dépôt (repli sur delivery_date pour les anciennes demandes).
        $phase = $request->shipping_phase ?: ($request->delivery_date ? 'delivered' : 'pending');

        if ($phase === 'pending') {
            // Commande non expédiée : annulation, aucun retour produit.
            $this->sendCustomerEmail(
                $request,
                'retractation_annulation',
                $this->l('Votre rétractation est validée — commande annulée avant expédition')
            );
            $this->confirmations[] = $this->l('Demande validée (commande non expédiée) : le client a été informé de l\'annulation. Pensez à annuler l\'expédition et à effectuer le remboursement depuis la fiche commande, puis marquez la demande comme remboursée.');
        } else {
            // Livrée ou en cours d'acheminement : procédure de retour.
            $request->setNativeReturnState(RetractationCommande::OR_STATE_WAITING_PACKAGE);
            $this->sendCustomerEmail(
                $request,
                'retractation_procedure',
                $this->l('Votre rétractation est validée — procédure de retour'),
                ['{procedure}' => Configuration::get('RETRACTATION_PROCEDURE_TEXT')]
            );
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
        if (!$request) {
            return;
        }

        $reason = trim((string) Tools::getValue('refusal_reason'));
        if (!$reason) {
            $this->errors[] = $this->l('Merci d\'indiquer le motif du refus (il sera communiqué au client).');

            return;
        }

        $request->status = RetractationRequest::STATUS_REFUSED;
        $request->refusal_reason = $reason;
        $request->update();
        $request->setNativeReturnState(RetractationCommande::OR_STATE_DENIED);

        $this->sendCustomerEmail(
            $request,
            'retractation_refus',
            $this->l('Votre demande de rétractation'),
            ['{reason}' => nl2br(htmlspecialchars($reason))]
        );

        $this->confirmations[] = $this->l('Demande refusée : le client a été informé du motif.');
    }

    /**
     * Produit reçu, contrôlé et remboursé. Le remboursement lui-même se fait
     * via la commande (remboursement standard/partiel natif).
     */
    protected function processRefund()
    {
        $request = $this->loadRequestOrFail();
        if (!$request) {
            return;
        }

        $request->status = RetractationRequest::STATUS_REFUNDED;
        $request->update();
        $request->setNativeReturnState(RetractationCommande::OR_STATE_COMPLETED);

        $this->sendCustomerEmail(
            $request,
            'retractation_remboursee',
            $this->l('Votre rétractation a été remboursée'),
            [
                '{refund_intro}' => $request->delivery_date
                    ? $this->l('le produit a été réceptionné et contrôlé, et')
                    : $this->l('votre commande a été annulée avant expédition, et'),
            ]
        );

        $this->confirmations[] = $this->l('Demande marquée comme remboursée, le client a été informé. Pensez à effectuer le remboursement réel depuis la fiche commande si ce n\'est pas déjà fait.');
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

    protected function sendCustomerEmail(RetractationRequest $request, $template, $subject, array $extraVars = [])
    {
        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($customer)) {
            return;
        }

        $vars = array_merge([
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_ref}' => $order->reference,
            '{request_ref}' => $request->reference,
            '{request_id}' => (int) $request->id,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
        ], $extraVars);

        Mail::Send(
            RetractationCommande::getMailLangId((int) $order->id_lang),
            $template,
            $subject . ' - ' . $order->reference,
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'retractationcommande/mails/',
            false,
            (int) $order->id_shop
        );
    }
}
