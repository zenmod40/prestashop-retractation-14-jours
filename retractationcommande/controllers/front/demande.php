<?php
/**
 * Front controller "demande" :
 *  - action=form    (ajax) : formulaire type de rétractation prérempli (modal)
 *  - action=confirm (ajax) : confirmation -> demande + retour natif + accusé PDF + emails
 *  - action=pdf            : téléchargement de l'accusé de réception
 *
 * La rétractation peut être partielle : le client choisit les produits et
 * quantités (la loi le permet ; remboursement des frais de livraison au
 * prorata en cas de renvoi partiel).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationDelai.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationRequest.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationPdf.php';

class RetractationCommandeDemandeModuleFrontController extends ModuleFrontController
{
    /**
     * Pas d'authentification requise : l'accès est protégé par un jeton par
     * commande dérivé de la clé secrète du client (infalsifiable), délivré
     * soit dans l'espace client, soit après vérification email + référence
     * dans le parcours invité (contrôleur "formulaire").
     */
    public $auth = false;
    public $ssl = true;

    public function postProcess()
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'form':
                $this->processForm();
                break;
            case 'confirm':
                $this->processConfirm();
                break;
            case 'pdf':
                $this->processPdfDownload();
                break;
            default:
                Tools::redirect('index.php?controller=history');
        }
    }

    /**
     * Charge la commande demandée en vérifiant le jeton de capacité.
     */
    protected function loadOrderOrFail()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)
            || !Tools::getValue('rtoken')
            || !hash_equals($this->module->getOrderToken($order), (string) Tools::getValue('rtoken'))) {
            $this->ajaxFail($this->module->l('Commande introuvable ou accès non autorisé.', 'demande'));
        }

        return $order;
    }

    /**
     * Client titulaire de la commande (connecté ou invité).
     */
    protected function getOrderCustomer(Order $order)
    {
        return new Customer((int) $order->id_customer);
    }

    protected function ajaxFail($message)
    {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $message]));
    }

    /**
     * Lignes de commande encore rétractables, avec quantité max.
     *
     * @return array lignes de $order->getProducts() + clé 'max_qty'
     */
    protected function getReturnableProducts(Order $order, array $remaining)
    {
        $products = [];
        foreach ($order->getProducts() as $product) {
            $max = (int) ($remaining[(int) $product['id_order_detail']] ?? 0);
            if ($max > 0) {
                $product['max_qty'] = $max;
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Formulaire type de rétractation prérempli (annexe art. R221-1 C. conso).
     */
    protected function processForm()
    {
        $order = $this->loadOrderOrFail();
        $eligibility = RetractationRequest::getOrderEligibility($order);
        if (!$eligibility['eligible']) {
            $this->ajaxFail($this->module->l('Cette commande n\'est plus éligible à la rétractation (délai légal expiré ou produits déjà en cours de rétractation).', 'demande'));
        }

        $customer = $this->getOrderCustomer($order);
        $address = new Address((int) $order->id_address_delivery);
        $invoiceAddress = new Address((int) $order->id_address_invoice);

        $this->context->smarty->assign([
            'rc_order' => $order,
            'rc_order_date' => Tools::displayDate($order->date_add),
            'rc_delivery_date' => $eligibility['delivered'] ? Tools::displayDate($eligibility['delivery_date']) : null,
            'rc_phase' => $eligibility['shipping_phase'],
            'rc_deadline_text' => $eligibility['deadline_text'],
            'rc_products' => $this->getReturnableProducts($order, $eligibility['remaining']),
            'rc_customer' => $customer,
            'rc_address' => Validate::isLoadedObject($address) ? $address : $invoiceAddress,
            'rc_shop_name' => Configuration::get('PS_SHOP_NAME'),
            'rc_shop_address' => $this->getShopAddress(),
            'rc_today' => date('d/m/Y'),
            'rc_token' => $this->module->getOrderToken($order),
            'rc_ajax_url' => $this->context->link->getModuleLink('retractationcommande', 'demande', []),
        ]);

        header('Content-Type: application/json');
        die(json_encode([
            'success' => true,
            'html' => $this->context->smarty->fetch('module:retractationcommande/views/templates/front/modal.tpl'),
        ]));
    }

    /**
     * Confirmation de la rétractation par le client.
     */
    protected function processConfirm()
    {
        $order = $this->loadOrderOrFail();
        $eligibility = RetractationRequest::getOrderEligibility($order);
        if (!$eligibility['eligible']) {
            $this->ajaxFail($this->module->l('Cette commande n\'est plus éligible à la rétractation (délai légal expiré ou produits déjà en cours de rétractation).', 'demande'));
        }

        $message = trim((string) Tools::getValue('rc_message'));
        if ($message && !Validate::isCleanHtml($message)) {
            $this->ajaxFail($this->module->l('Le message contient des caractères non autorisés.', 'demande'));
        }

        // Sélection produits/quantités, validée contre les quantités restantes.
        $remaining = $eligibility['remaining'];
        $rawQty = Tools::getValue('returnQty');
        $selection = [];
        if (is_array($rawQty)) {
            foreach ($rawQty as $idOrderDetail => $qty) {
                $idOrderDetail = (int) $idOrderDetail;
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > (int) ($remaining[$idOrderDetail] ?? 0)) {
                    $this->ajaxFail($this->module->l('Quantité demandée supérieure à la quantité rétractable.', 'demande'));
                }
                $selection[$idOrderDetail] = $qty;
            }
        }
        if (!$selection) {
            $this->ajaxFail($this->module->l('Sélectionnez au moins un produit à rétracter.', 'demande'));
        }

        // Snapshot des produits demandés (preuve + affichage BO/PDF/emails).
        // La rétractation est totale si elle couvre toutes les quantités commandées.
        $selectedProducts = [];
        $isFullOrder = true;
        foreach ($order->getProducts() as $product) {
            $idOrderDetail = (int) $product['id_order_detail'];
            $qty = (int) ($selection[$idOrderDetail] ?? 0);
            if ($qty < (int) $product['product_quantity']) {
                $isFullOrder = false;
            }
            if ($qty > 0) {
                $unitPrice = (float) ($product['unit_price_tax_incl'] ?? $product['product_price_wt'] ?? 0);
                $selectedProducts[] = [
                    'id_order_detail' => $idOrderDetail,
                    'product_name' => $product['product_name'],
                    'product_reference' => $product['product_reference'] ?? '',
                    'product_quantity' => $qty,
                    'quantity' => $qty,
                    'unit_price_tax_incl' => $unitPrice,
                    'total_tax_incl' => $unitPrice * $qty,
                ];
            }
        }

        $delivered = $eligibility['delivered'];
        $phase = $eligibility['shipping_phase']; // delivered | shipped | pending
        $deadline = $eligibility['deadline'];

        $request = new RetractationRequest();
        $request->id_shop = (int) $order->id_shop;
        $request->id_order = (int) $order->id;
        $request->id_customer = (int) $order->id_customer;
        $request->reference = RetractationRequest::generateReference();
        $request->status = RetractationRequest::STATUS_PENDING;
        $request->message = $message ?: null;
        $request->products_snapshot = json_encode($selectedProducts, JSON_UNESCAPED_UNICODE);
        $request->delivery_date = $delivered ? $eligibility['delivery_date'] : null;
        $request->shipping_phase = $phase;
        $request->legal_deadline = $deadline ? $deadline->format('Y-m-d H:i:s') : null;
        $request->within_deadline = 1; // bouton visible => dépôt dans la fenêtre légale

        // Retour natif PrestaShop (SAV > Retours produits)
        if (Configuration::get('RETRACTATION_CREATE_ORDER_RETURN')) {
            $request->createNativeOrderReturn($order, $selection);
        }

        if (!$request->add()) {
            $this->ajaxFail($this->module->l('Impossible d\'enregistrer la demande. Merci de contacter le service client.', 'demande'));
        }

        // Accusé de réception PDF (obligatoire : dépôt via le site, L221-21 al.3)
        // Page 2 : rappel des droits (et PDF multi-pages => affiché en icône
        // de pièce jointe par Apple Mail au lieu d'être prévisualisé en ligne).
        $pdfHtml = $this->renderAcknowledgmentHtml($order, $request, $eligibility, $selectedProducts, $isFullOrder);
        $pdfRightsHtml = $this->context->smarty->fetch('module:retractationcommande/views/templates/front/pdf-droits.tpl');
        $pdfFilename = RetractationPdf::generate($pdfHtml, (int) $request->id, $pdfRightsHtml);
        if ($pdfFilename) {
            $request->pdf_filename = $pdfFilename;
            $request->update();
        }

        $this->sendEmails($order, $request, $pdfFilename, $selectedProducts);

        if ($phase === 'delivered') {
            $successMessage = $this->module->l('Votre rétractation a bien été enregistrée sous la référence %s. Un accusé de réception vous a été envoyé par email. Notre service client va vérifier votre demande et vous transmettre la procédure de retour.', 'demande');
        } elseif ($phase === 'shipped') {
            $successMessage = $this->module->l('Votre rétractation a bien été enregistrée sous la référence %s. Un accusé de réception vous a été envoyé par email. Votre commande étant déjà expédiée, vous pouvez refuser le colis à sa présentation ou, si vous le recevez, suivre la procédure de retour que notre service client va vous transmettre.', 'demande');
        } else {
            $successMessage = $this->module->l('Votre rétractation a bien été enregistrée sous la référence %s. Un accusé de réception vous a été envoyé par email. Votre commande n\'ayant pas encore été expédiée, aucun retour de produit ne sera nécessaire : après vérification, l\'expédition sera annulée et vous serez remboursé(e).', 'demande');
        }

        header('Content-Type: application/json');
        die(json_encode([
            'success' => true,
            'message' => sprintf($successMessage, $request->reference),
            'pdf_url' => $pdfFilename ? $this->context->link->getModuleLink('retractationcommande', 'demande', [
                'action' => 'pdf',
                'id_order' => (int) $order->id,
                'rtoken' => $this->module->getOrderToken($order),
            ]) : null,
        ]));
    }

    /**
     * Adresse de la boutique sans séparateurs orphelins quand des champs
     * sont vides dans la configuration.
     */
    protected function getShopAddress()
    {
        $line1 = trim(Configuration::get('PS_SHOP_ADDR1') . ' ' . Configuration::get('PS_SHOP_ADDR2'));
        $line2 = trim(Configuration::get('PS_SHOP_CODE') . ' ' . Configuration::get('PS_SHOP_CITY'));

        return implode(', ', array_filter([$line1, $line2]));
    }

    /**
     * Chemin du logo boutique pour le PDF (fichier local lisible par TCPDF).
     */
    protected function getShopLogoPath()
    {
        foreach ([Configuration::get('PS_LOGO_MAIL'), Configuration::get('PS_LOGO')] as $logo) {
            if ($logo && file_exists(_PS_IMG_DIR_ . $logo)) {
                return _PS_IMG_DIR_ . $logo;
            }
        }

        return null;
    }

    /**
     * HTML de l'accusé de réception (rendu en PDF).
     */
    protected function renderAcknowledgmentHtml(Order $order, RetractationRequest $request, array $eligibility, array $selectedProducts, $isFullOrder)
    {
        $address = new Address((int) $order->id_address_delivery);
        $currency = new Currency((int) $order->id_currency);

        $productsTotal = 0.0;
        foreach ($selectedProducts as $product) {
            $productsTotal += (float) $product['total_tax_incl'];
        }
        $shipping = (float) $order->total_shipping_tax_incl;
        // Rétractation totale : remboursement intégral, livraison standard incluse.
        // Partielle : frais de livraison remboursés au prorata (estimation SAV).
        $refundTotal = $isFullOrder ? $productsTotal + $shipping : $productsTotal;

        foreach ($selectedProducts as &$product) {
            $product['unit_price_formatted'] = RetractationRequest::formatPrice((float) $product['unit_price_tax_incl'], $currency);
            $product['total_formatted'] = RetractationRequest::formatPrice((float) $product['total_tax_incl'], $currency);
        }
        unset($product);

        $this->context->smarty->assign([
            'rc_request_ref' => $request->reference,
            'rc_request_date' => Tools::displayDate(date('Y-m-d H:i:s'), true),
            'rc_is_delivered' => (bool) $eligibility['delivered'],
            'rc_phase' => $eligibility['shipping_phase'],
            'rc_is_full_order' => $isFullOrder,
            'rc_products_total' => RetractationRequest::formatPrice($productsTotal, $currency),
            'rc_shipping_total' => RetractationRequest::formatPrice($shipping, $currency),
            'rc_refund_total' => RetractationRequest::formatPrice($refundTotal, $currency),
            'rc_currency' => $currency,
            'rc_order' => $order,
            'rc_order_date' => Tools::displayDate($order->date_add),
            'rc_delivery_date' => $eligibility['delivered'] ? Tools::displayDate($eligibility['delivery_date']) : null,
            'rc_products' => $selectedProducts,
            'rc_customer' => $this->getOrderCustomer($order),
            'rc_address' => $address,
            'rc_message' => $request->message,
            'rc_logo_path' => $this->getShopLogoPath(),
            'rc_shop_name' => Configuration::get('PS_SHOP_NAME'),
            'rc_shop_address' => $this->getShopAddress(),
            'rc_shop_email' => Configuration::get('PS_SHOP_EMAIL'),
        ]);

        return $this->context->smarty->fetch('module:retractationcommande/views/templates/front/pdf-accuse.tpl');
    }

    /**
     * Accusé de réception au client (+ PDF joint) et notification SAV.
     */
    protected function sendEmails(Order $order, RetractationRequest $request, $pdfFilename, array $selectedProducts)
    {
        $customer = $this->getOrderCustomer($order);
        $idLang = RetractationCommande::getMailLangId((int) $order->id_lang);
        $mailDir = _PS_MODULE_DIR_ . 'retractationcommande/mails/';

        $attachment = null;
        if ($pdfFilename && ($path = RetractationPdf::getPath($pdfFilename))) {
            $attachment = [
                'content' => Tools::file_get_contents($path),
                'name' => 'accuse-reception-retractation-' . $order->reference . '.pdf',
                'mime' => 'application/pdf',
            ];
        }

        $productLines = [];
        foreach ($selectedProducts as $product) {
            $productLines[] = $product['product_quantity'] . ' x ' . $product['product_name'];
        }

        // Textes adaptés aux 3 phases : livré (retour produit), expédié
        // (colis en transit : refus/retour), non expédié (annulation).
        $phase = $request->shipping_phase ?: ((bool) $request->delivery_date ? 'delivered' : 'pending');
        if ($phase === 'delivered') {
            $nextSteps = $this->module->l('Notre service client va vérifier l\'éligibilité de votre demande (délai légal de 14 jours, exclusions de l\'article L221-28). Si elle est conforme, la procédure de retour vous sera transmise par email, puis le remboursement interviendra au plus tard 14 jours après récupération du bien ou réception de la preuve d\'expédition. Merci de ne pas renvoyer le produit avant d\'avoir reçu la procédure de retour.', 'demande');
            $savChecklist = $this->module->l('À vérifier : délai de 14 jours, exclusions légales (art. L221-28), produits déjà retournés.', 'demande');
        } elseif ($phase === 'shipped') {
            $nextSteps = $this->module->l('Votre commande est déjà expédiée et en cours d\'acheminement. Vous pouvez refuser le colis à sa présentation ; si vous le recevez, ne l\'ouvrez pas et attendez la procédure de retour que notre service client vous transmettra après vérification. Le remboursement interviendra au plus tard 14 jours après récupération du bien ou réception de la preuve de son renvoi.', 'demande');
            $savChecklist = $this->module->l('COMMANDE EXPÉDIÉE (en transit) — le colis est parti : prévoir un refus de colis ou un retour. À vérifier : exclusions légales (art. L221-28). Le délai de 14 jours ne démarrera qu\'à la livraison.', 'demande');
        } else {
            $nextSteps = $this->module->l('Votre commande n\'ayant pas encore été expédiée, aucun retour de produit ne sera nécessaire. Après vérification de votre demande par notre service client, l\'expédition sera annulée et la totalité des sommes versées vous sera remboursée au plus tard 14 jours après votre demande, par le même moyen de paiement (art. L221-24).', 'demande');
            $savChecklist = $this->module->l('COMMANDE NON EXPÉDIÉE — annulation avant expédition : aucun retour produit attendu. À vérifier : exclusions légales (art. L221-28). Pensez à bloquer l\'expédition avant validation.', 'demande');
        }
        $delivered = ($phase === 'delivered');

        $vars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{email}' => $customer->email,
            '{request_ref}' => $request->reference,
            '{order_ref}' => $order->reference,
            '{order_date}' => Tools::displayDate($order->date_add),
            '{delivery_date}' => $delivered
                ? Tools::displayDate($request->delivery_date)
                : ($phase === 'shipped'
                    ? $this->module->l('Expédiée, en cours d\'acheminement', 'demande')
                    : $this->module->l('Non expédiée au moment de la demande', 'demande')),
            '{deadline}' => $request->legal_deadline ? Tools::displayDate($request->legal_deadline) : '-',
            '{request_id}' => (int) $request->id,
            '{next_steps}' => $nextSteps,
            '{sav_checklist}' => $savChecklist,
            '{products}' => implode('<br>', array_map('htmlspecialchars', $productLines)),
            '{message}' => $request->message ? nl2br(htmlspecialchars($request->message)) : '-',
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
        ];

        // 1) Accusé de réception client
        Mail::Send(
            $idLang,
            'retractation_accuse',
            $this->module->l('Accusé de réception de votre demande de rétractation', 'demande') . ' - ' . $order->reference,
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            $attachment,
            null,
            $mailDir,
            false,
            (int) $order->id_shop
        );

        // 2) Notification SAV
        $savEmail = Configuration::get('RETRACTATION_SAV_EMAIL') ?: Configuration::get('PS_SHOP_EMAIL');
        if ($savEmail) {
            Mail::Send(
                $idLang,
                'retractation_sav',
                $this->module->l('Nouvelle demande de rétractation à vérifier', 'demande') . ' - ' . $order->reference,
                $vars,
                $savEmail,
                null,
                null,
                null,
                $attachment,
                null,
                $mailDir,
                false,
                (int) $order->id_shop
            );
        }
    }

    /**
     * Téléchargement de l'accusé PDF par le client propriétaire.
     */
    protected function processPdfDownload()
    {
        $order = $this->loadOrderOrFail();
        $row = RetractationRequest::getByOrder((int) $order->id);
        $path = $row ? RetractationPdf::getPath($row['pdf_filename']) : null;

        if (!$path) {
            Tools::redirect('index.php?controller=history');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="accuse-reception-retractation-' . $order->reference . '.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
