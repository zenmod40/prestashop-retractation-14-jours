<?php
/**
 * Gestes SAV sur une demande de rétractation : accepter, refuser, marquer
 * remboursée. Point d'entrée unique du back-office et des modules tiers
 * (Régie Bridge) : mêmes e-mails, même bon de retour PDF, même
 * synchronisation du retour natif, quel que soit l'appelant.
 *
 * Indépendant du contrôleur admin : fonctionne depuis un contrôleur front ou
 * la ligne de commande, l'employé étant passé en paramètre.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RetractationRequest.php';
require_once __DIR__ . '/RetractationPdf.php';
require_once __DIR__ . '/RetractationPhoto.php';

class RetractationWorkflowException extends Exception
{
    // getMessage() : code stable en snake_case (bad_status, reason_required, reason_invalid, save_failed).
    // Avertissements (état enregistré) dans le tableau renvoyé : mail_failed, pdf_failed, history_failed.
}

class RetractationWorkflow
{
    /** Incrémentée à chaque changement du contrat public. */
    const API_VERSION = 1;

    /**
     * pending → accepted. Commande non expédiée : e-mail d'annulation.
     * Sinon : retour natif « En attente du colis », procédure + bon de retour PDF.
     *
     * @return array ['status' => 'accepted', 'emails' => string[], 'warnings' => string[]]
     *
     * @throws RetractationWorkflowException
     */
    public static function accept(RetractationRequest $request, $idEmployee, $source = 'bo')
    {
        $logged = self::transition($request, RetractationRequest::STATUS_PENDING, RetractationRequest::STATUS_ACCEPTED, $idEmployee, $source);
        $result = self::result($request, $logged);

        // Phase figée au dépôt (repli sur delivery_date pour les anciennes demandes).
        if (self::phase($request) === 'pending') {
            self::sendCustomerEmail(
                $result,
                $request,
                'retractation_annulation',
                self::l('Votre rétractation est validée — commande annulée avant expédition')
            );

            return $result;
        }

        // Livrée ou en cours d'acheminement : procédure de retour.
        $request->setNativeReturnState(RetractationCommande::OR_STATE_WAITING_PACKAGE);

        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);

        // Procédure (texte du jeu de règles du client : par défaut ou par
        // groupe) + adresse de retour configurée (centre logistique…) si
        // renseignée — ajoutée au contenu sans toucher aux templates.
        $procedure = (string) RetractationRules::forCustomer((int) $request->id_customer)['procedure_text'];
        $returnAddress = trim((string) Configuration::get('RETRACTATION_RETURN_ADDRESS'));
        if ($returnAddress !== '') {
            $procedure .= '<p style="margin-top:14px"><strong>' . self::l('Adresse de retour') . ' :</strong><br>'
                . nl2br(htmlspecialchars($returnAddress, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        // Instructions spécifiques (config) — affichées dans l'e-mail ET sur le bon de retour.
        $instructions = trim((string) Configuration::get('RETRACTATION_RETURN_INSTRUCTIONS'));
        if ($instructions !== '') {
            $procedure .= '<p style="margin-top:14px"><strong>' . self::l('Instructions') . ' :</strong><br>' . $instructions . '</p>';
        }

        // Bon de retour PDF joint + consigne d'impression / collage sur le colis.
        $attachment = null;
        if (Validate::isLoadedObject($order) && Validate::isLoadedObject($customer)) {
            $attachment = self::buildReturnSlipAttachment($request, $order, $customer);
            if (!$attachment) {
                $result['warnings'][] = 'pdf_failed';
            }
        }
        if ($attachment) {
            $procedure .= '<p style="margin-top:14px; padding:10px 12px; border:2px solid #2e7d32; background:#eef5ee; color:#1b4d20;"><strong>'
                . self::l('Un bon de retour est joint à cet e-mail (PDF). Imprimez-le et collez-le sur l\'extérieur du colis : sans ce bon, votre retour ne pourra pas être accepté par notre service logistique.')
                . '</strong></p>';
        }

        self::sendCustomerEmail(
            $result,
            $request,
            'retractation_procedure',
            self::l('Votre rétractation est validée — procédure de retour'),
            ['{procedure}' => $procedure],
            $attachment
        );

        return $result;
    }

    /**
     * pending → refused. Motif obligatoire (communiqué au client).
     *
     * @throws RetractationWorkflowException
     */
    public static function refuse(RetractationRequest $request, $reason, $idEmployee, $source = 'bo')
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new RetractationWorkflowException('reason_required');
        }
        if (!Validate::isCleanHtml($reason)) {
            throw new RetractationWorkflowException('reason_invalid');
        }

        $logged = self::transition($request, RetractationRequest::STATUS_PENDING, RetractationRequest::STATUS_REFUSED, $idEmployee, $source, $reason);
        $result = self::result($request, $logged);
        $request->setNativeReturnState(RetractationCommande::OR_STATE_DENIED);

        self::sendCustomerEmail(
            $result,
            $request,
            'retractation_refus',
            self::l('Votre demande de rétractation'),
            ['{reason}' => nl2br(htmlspecialchars($reason))]
        );

        return $result;
    }

    /**
     * accepted → refunded. Informe le client ; le remboursement réel se fait
     * depuis la commande (remboursement standard/partiel natif).
     *
     * @throws RetractationWorkflowException
     */
    public static function markRefunded(RetractationRequest $request, $idEmployee, $source = 'bo')
    {
        $logged = self::transition($request, RetractationRequest::STATUS_ACCEPTED, RetractationRequest::STATUS_REFUNDED, $idEmployee, $source);
        $result = self::result($request, $logged);
        $request->setNativeReturnState(RetractationCommande::OR_STATE_COMPLETED);

        self::sendCustomerEmail(
            $result,
            $request,
            'retractation_remboursee',
            self::l('Votre rétractation a été remboursée'),
            [
                '{refund_intro}' => self::phase($request) === 'pending'
                    ? self::l('votre commande a été annulée avant expédition, et')
                    : self::l('le produit a été réceptionné et contrôlé, et'),
            ]
        );

        return $result;
    }

    /**
     * Chemins absolus des photos jointes par le client (fichiers présents uniquement).
     *
     * @return string[]
     */
    public static function photoPaths(RetractationRequest $request)
    {
        $paths = [];
        $names = json_decode((string) $request->photos, true);
        foreach (is_array($names) ? $names : [] as $name) {
            $path = RetractationPhoto::getPath(basename((string) $name));
            if ($path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Chemin absolu de l'accusé de réception PDF, null s'il n'existe pas
     * (retour commercial, TCPDF indisponible au dépôt, fichier supprimé).
     */
    public static function acknowledgmentPdfPath(RetractationRequest $request)
    {
        return RetractationPdf::getPath($request->pdf_filename);
    }

    /**
     * Historique de la demande, du plus ancien au plus récent.
     *
     * @return array[] status_from, status_to, id_employee, employee, source, comment, date_add
     */
    public static function history(RetractationRequest $request)
    {
        try {
            $rows = Db::getInstance()->executeS('
                SELECT h.`status_from`, h.`status_to`, h.`id_employee`,
                       TRIM(CONCAT(IFNULL(e.`firstname`, \'\'), \' \', IFNULL(e.`lastname`, \'\'))) AS `employee`,
                       h.`source`, h.`comment`, h.`date_add`
                FROM `' . _DB_PREFIX_ . 'retractation_request_history` h
                LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (e.`id_employee` = h.`id_employee`)
                WHERE h.`id_retractation_request` = ' . (int) $request->id . '
                ORDER BY h.`date_add` ASC, h.`id_retractation_request_history` ASC');
        } catch (Exception $e) {
            $rows = false; // table absente tant que l'upgrade 1.6.0 n'a pas tourné
        }

        // Types stables pour l'appelant : PS 8 rend des chaînes, PS 9 des entiers.
        return array_map(static function ($row) {
            $row['id_employee'] = (int) $row['id_employee'];

            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Ajoute une ligne d'historique. Public pour la création côté client
     * ('' → pending, source front). Ne lève jamais : un historique en échec
     * ne doit bloquer ni le dépôt du client ni l'e-mail d'un geste SAV.
     *
     * @return bool
     */
    public static function logHistory($idRequest, $from, $to, $idEmployee, $source, $comment = null)
    {
        $row = [
            'id_retractation_request' => (int) $idRequest,
            'status_from' => pSQL($from),
            'status_to' => pSQL($to),
            'id_employee' => (int) $idEmployee,
            'source' => pSQL($source),
            'date_add' => date('Y-m-d H:i:s'),
        ];
        if ($comment !== null) {
            $row['comment'] = pSQL($comment, true);
        }

        try {
            // $null_values à false : sinon Db::insert() change '' (status_from d'une création) en NULL.
            return (bool) Db::getInstance()->insert('retractation_request_history', $row);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Change l'état si, et seulement si, la demande est encore dans l'état
     * attendu en base : la condition sur `status` dans l'UPDATE rend le geste
     * sûr face à un double clic ou à deux postes simultanés (BO + Régie).
     *
     * @return bool ligne d'historique écrite
     *
     * @throws RetractationWorkflowException
     */
    protected static function transition(RetractationRequest $request, $from, $to, $idEmployee, $source, $reason = null)
    {
        if (!Validate::isLoadedObject($request) || $request->status !== $from) {
            throw new RetractationWorkflowException('bad_status');
        }

        $now = date('Y-m-d H:i:s');
        $fields = ['status' => pSQL($to), 'date_upd' => $now];
        if ($reason !== null) {
            $fields['refusal_reason'] = pSQL($reason, true); // comme ObjectModel (TYPE_HTML) : garde les sauts de ligne
        }

        $db = Db::getInstance();
        try {
            // PS 8 lève sur une erreur SQL, PS 9 rend false : même issue pour l'appelant.
            $saved = $db->update('retractation_request', $fields, '`id_retractation_request` = ' . (int) $request->id . ' AND `status` = \'' . pSQL($from) . '\'');
        } catch (Exception $e) {
            $saved = false;
        }
        if (!$saved) {
            throw new RetractationWorkflowException('save_failed');
        }
        if ((int) $db->Affected_Rows() !== 1) {
            // Un autre poste est passé entre la lecture et l'écriture.
            throw new RetractationWorkflowException('bad_status');
        }

        // Écriture hors ObjectModel::update() : vider le cache objet, sinon un
        // new RetractationRequest() dans le même processus relit l'ancien état.
        $request->clearCache();
        $request->status = $to;
        $request->date_upd = $now;
        if ($reason !== null) {
            $request->refusal_reason = $reason;
        }

        return self::logHistory($request->id, $from, $to, $idEmployee, $source, $reason);
    }

    protected static function result(RetractationRequest $request, $historyLogged)
    {
        return ['status' => $request->status, 'emails' => [], 'warnings' => $historyLogged ? [] : ['history_failed']];
    }

    protected static function phase(RetractationRequest $request)
    {
        return $request->shipping_phase ?: ($request->delivery_date ? 'delivered' : 'pending');
    }

    protected static function l($string)
    {
        static $module = null;
        if ($module === null) {
            $module = Module::getInstanceByName('retractationcommande');
        }

        // Même clé de traduction qu'avant l'extraction (contrôleur admin).
        return $module ? $module->l($string, 'adminretractationcontroller') : $string;
    }

    /**
     * Envoie l'e-mail client et consigne le résultat dans $result
     * (gabarit dans 'emails' si parti, 'mail_failed' dans 'warnings' sinon).
     */
    protected static function sendCustomerEmail(array &$result, RetractationRequest $request, $template, $subject, array $extraVars = [], $attachment = null)
    {
        $order = new Order((int) $request->id_order);
        $customer = new Customer((int) $request->id_customer);
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($customer)) {
            $result['warnings'][] = 'mail_failed';

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

        // Retour commercial (jeu par groupe) : un seul template neutre, sans
        // mention du droit de rétractation ; titre, intro et corps selon l'étape.
        if (!RetractationRules::forCustomer((int) $customer->id)['legal']) {
            $refs = [$request->reference, $order->reference];
            switch ($template) {
                case 'retractation_annulation':
                    $subject = self::l('Votre demande de retour est acceptée — commande annulée avant expédition');
                    $intro = vsprintf(self::l('Votre demande %s concernant la commande %s a été acceptée.'), $refs);
                    $body = '<p>' . self::l('Votre commande n\'ayant pas encore été expédiée, aucun retour de produit n\'est nécessaire : l\'expédition est annulée et le remboursement vous sera adressé par le même moyen de paiement.') . '</p>';
                    break;
                case 'retractation_refus':
                    $subject = self::l('Votre demande de retour');
                    $intro = vsprintf(self::l('Votre demande de retour %s concernant la commande %s n\'a pas pu être acceptée.'), $refs);
                    $body = '<p><strong>' . self::l('Motif') . '</strong><br>' . ($vars['{reason}'] ?? '') . '</p>';
                    break;
                case 'retractation_remboursee':
                    $subject = self::l('Votre retour a été remboursé');
                    $intro = vsprintf(self::l('Votre demande de retour %s concernant la commande %s a été remboursée.'), $refs);
                    $body = '<p>' . self::l('Le remboursement a été effectué par le même moyen de paiement que celui de la commande.') . '</p>';
                    break;
                default: // retractation_procedure
                    $subject = self::l('Votre demande de retour est acceptée — procédure de retour');
                    $intro = vsprintf(self::l('Votre demande de retour %s concernant la commande %s a été vérifiée et acceptée.'), $refs);
                    $body = (string) ($vars['{procedure}'] ?? '');
            }
            $template = 'retour_notification';
            $vars['{title}'] = $subject;
            $vars['{intro}'] = $intro;
            $vars['{body}'] = $body;
        }

        $sent = Mail::Send(
            RetractationCommande::getMailLangId((int) $order->id_lang, $template),
            $template,
            $subject . ' - ' . $order->reference,
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            $attachment,
            null,
            _PS_MODULE_DIR_ . 'retractationcommande/mails/',
            false,
            (int) $order->id_shop
        );

        if ($sent) {
            $result['emails'][] = $template;
        } else {
            $result['warnings'][] = 'mail_failed';
        }
    }

    /**
     * Génère le PDF « bon de retour » (à imprimer et coller sur le colis) et
     * retourne une pièce jointe prête pour Mail::Send, ou null si indisponible.
     */
    protected static function buildReturnSlipAttachment(RetractationRequest $request, Order $order, Customer $customer)
    {
        $module = Module::getInstanceByName('retractationcommande');
        if (!$module) {
            return null;
        }

        $products = RetractationRequest::decodeSnapshot($request->products_snapshot);
        $returnAddress = trim((string) Configuration::get('RETRACTATION_RETURN_ADDRESS'));
        $instructions = trim((string) Configuration::get('RETRACTATION_RETURN_INSTRUCTIONS'));

        Context::getContext()->smarty->assign([
            'rc_shop_name' => Configuration::get('PS_SHOP_NAME'),
            'rc_customer_name' => trim($customer->firstname . ' ' . $customer->lastname),
            'rc_order_ref' => $order->reference,
            'rc_request_ref' => $request->reference,
            'rc_date' => Tools::displayDate(date('Y-m-d H:i:s'), true),
            'rc_products' => is_array($products) ? $products : [],
            'rc_return_address' => $returnAddress !== '' ? nl2br(htmlspecialchars($returnAddress, ENT_QUOTES, 'UTF-8')) : '',
            'rc_instructions' => $instructions !== '' ? $instructions : '',
        ]);

        // display() (et non fetch('module:...')) : résolution fiable du template
        // hors contexte front (back-office, ligne de commande).
        $html = $module->display(
            _PS_MODULE_DIR_ . 'retractationcommande/retractationcommande.php',
            'views/templates/front/pdf-bon-retour.tpl'
        );
        $filename = RetractationPdf::generate($html, (int) $request->id, null, 'bon_retour');
        $path = $filename ? RetractationPdf::getPath($filename) : null;
        if (!$path) {
            return null;
        }

        return [
            'content' => file_get_contents($path),
            'name' => 'bon-de-retour-' . $order->reference . '.pdf',
            'mime' => 'application/pdf',
        ];
    }
}
