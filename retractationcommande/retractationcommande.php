<?php
/**
 * Rétractation Commande — Droit de rétractation légal (art. L221-18 s. Code de la consommation,
 * ordonnance n°2026-2 / décret n°2026-3 : fonctionnalité de rétractation en ligne obligatoire
 * à compter du 19 juin 2026).
 *
 * S'appuie sur le système natif de retours produits PrestaShop (OrderReturn)
 * et ajoute la couche légale : fenêtre de 14 jours (départ le lendemain de la
 * livraison, prolongation au 1er jour ouvrable), formulaire type de rétractation
 * (annexe art. R221-1), accusé de réception PDF + email (art. L221-21 al.3),
 * lien visible sur tout le site (footer), parcours invité, liste de vérification SAV.
 *
 * @author    Magic Garden <https://magicgarden.fr>
 * @copyright 2026 Magic Garden
 * @license   GPL-3.0-or-later — https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://magicgarden.fr
 *
 * Aide à la mise en conformité : ne constitue pas un conseil juridique.
 * Le marchand reste seul responsable de la conformité de sa boutique.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/RetractationDelai.php';
require_once __DIR__ . '/classes/RetractationRequest.php';

class RetractationCommande extends Module
{
    /** États natifs de ps_order_return_state */
    const OR_STATE_WAITING_CONFIRMATION = 1; // En attente de confirmation
    const OR_STATE_WAITING_PACKAGE = 2;      // En attente du colis
    const OR_STATE_PACKAGE_RECEIVED = 3;     // Colis reçu
    const OR_STATE_DENIED = 4;               // Retour refusé
    const OR_STATE_COMPLETED = 5;            // Retour terminé

    public function __construct()
    {
        $this->name = 'retractationcommande';
        $this->tab = 'administration';
        $this->version = '1.4.0';
        $this->author = 'Magic Garden';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Rétractation de commande (loi 14 jours)');
        $this->description = $this->l('Fonctionnalité de rétractation en ligne (ordonnance n°2026-2) : lien visible sur tout le site, bouton dans l\'espace client pendant le délai légal, parcours invité, formulaire type, accusé de réception PDF, liste de vérification SAV adossée aux retours natifs.');
        $this->confirmUninstall = $this->l('Les demandes de rétractation enregistrées seront conservées en base de données (preuves légales) et retrouvées en cas de réinstallation. Continuer ?');
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installTab()
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('displayFooter')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('moduleRoutes')
            && Configuration::updateValue('RETRACTATION_SAV_EMAIL', Configuration::get('PS_SHOP_EMAIL'))
            && Configuration::updateValue('RETRACTATION_CREATE_ORDER_RETURN', 1)
            && Configuration::updateValue('RETRACTATION_ALLOW_UNDELIVERED', 1)
            && Configuration::updateValue('RETRACTATION_HIDE_NATIVE_FORM', 1)
            && Configuration::updateValue('RETRACTATION_DELAY_DAYS', 14)
            && Configuration::updateValue('RETRACTATION_SHOW_FOOTER_LINK', 1)
            && Configuration::updateValue('RETRACTATION_LINK_LABEL', 'Exercer mon droit de rétractation')
            && Configuration::updateValue('RETRACTATION_EXCLUDED_CATS', '')
            && Configuration::updateValue('RETRACTATION_EXCLUDED_PRODUCTS', '')
            && Configuration::updateValue('RETRACTATION_PROCEDURE_TEXT', $this->getDefaultProcedureText(), true)
            && $this->installDefaultStateMapping();
    }

    /**
     * Pré-remplit le mapping des statuts par détection automatique
     * (drapeaux natifs + mots-clés) dès l'installation : le module est
     * opérationnel sans configuration manuelle.
     */
    protected function installDefaultStateMapping()
    {
        $map = RetractationRequest::suggestStateMapping();
        Configuration::updateValue('RETRACTATION_DELIVERED_STATES', implode(',', $map['DELIVERED']));
        Configuration::updateValue('RETRACTATION_SHIPPED_STATES', implode(',', $map['SHIPPED']));
        Configuration::updateValue('RETRACTATION_BLOCKED_STATES', implode(',', $map['BLOCKED']));

        return true;
    }

    public function uninstall()
    {
        foreach ([
            'RETRACTATION_SAV_EMAIL', 'RETRACTATION_CREATE_ORDER_RETURN', 'RETRACTATION_ALLOW_UNDELIVERED',
            'RETRACTATION_HIDE_NATIVE_FORM', 'RETRACTATION_DELAY_DAYS', 'RETRACTATION_LINK_LABEL',
            'RETRACTATION_SHOW_FOOTER_LINK', 'RETRACTATION_DELIVERED_STATES', 'RETRACTATION_SHIPPED_STATES', 'RETRACTATION_BLOCKED_STATES',
            'RETRACTATION_EXCLUDED_CATS', 'RETRACTATION_EXCLUDED_PRODUCTS', 'RETRACTATION_PROCEDURE_TEXT',
        ] as $key) {
            Configuration::deleteByName($key);
        }

        // La table retractation_request est volontairement conservée :
        // les demandes de rétractation sont des preuves légales et doivent
        // survivre à une réinstallation du module.
        return $this->uninstallTab()
            && parent::uninstall();
    }

    protected function installDb()
    {
        return $this->createDbTable() && $this->migrateDbColumns();
    }

    protected function createDbTable()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'retractation_request` (
                `id_retractation_request` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_order` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `id_order_return` INT UNSIGNED NOT NULL DEFAULT 0,
                `reference` VARCHAR(16) NOT NULL DEFAULT \'\',
                `status` VARCHAR(32) NOT NULL DEFAULT \'pending\',
                `message` TEXT,
                `refusal_reason` TEXT,
                `products_snapshot` TEXT,
                `delivery_date` DATETIME DEFAULT NULL,
                `shipping_phase` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `legal_deadline` DATETIME DEFAULT NULL,
                `within_deadline` TINYINT(1) NOT NULL DEFAULT 1,
                `pdf_filename` VARCHAR(255) DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_retractation_request`),
                KEY `id_order` (`id_order`),
                KEY `id_customer` (`id_customer`),
                UNIQUE KEY `reference` (`reference`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Ajoute les colonnes manquantes si la table provient d'une version
     * antérieure du module (la table est conservée à la désinstallation).
     */
    protected function migrateDbColumns()
    {
        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'retractation_request`');
        $existing = array_column($columns ?: [], 'Field');

        $migrations = [
            'reference' => 'ALTER TABLE `' . _DB_PREFIX_ . 'retractation_request` ADD `reference` VARCHAR(16) NOT NULL DEFAULT \'\' AFTER `id_order_return`',
            'products_snapshot' => 'ALTER TABLE `' . _DB_PREFIX_ . 'retractation_request` ADD `products_snapshot` TEXT AFTER `refusal_reason`',
            'shipping_phase' => 'ALTER TABLE `' . _DB_PREFIX_ . 'retractation_request` ADD `shipping_phase` VARCHAR(16) NOT NULL DEFAULT \'pending\' AFTER `delivery_date`',
        ];
        foreach ($migrations as $column => $sql) {
            if (!in_array($column, $existing, true) && !Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    protected function installTab()
    {
        $tab = new Tab();
        $tab->class_name = 'AdminRetractation';
        $tab->module = $this->name;
        $tab->active = 1;
        // Sous le menu SAV (Service client)
        $idParent = (int) Tab::getIdFromClassName('AdminParentCustomerThreads');
        $tab->id_parent = $idParent ?: (int) Tab::getIdFromClassName('AdminParentCustomer');
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = ($lang['iso_code'] === 'fr') ? 'Rétractations' : 'Withdrawal requests';
        }

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminRetractation');
        if ($idTab) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }

    protected function getDefaultProcedureText()
    {
        return '<p>Votre demande de rétractation a été validée. Merci de nous retourner le(s) produit(s) '
            . 'dans leur état d\'origine, complet(s) (accessoires, notices, emballage), sous 14 jours, à l\'adresse suivante :</p>'
            . '<p><strong>' . Configuration::get('PS_SHOP_NAME') . '</strong><br>'
            . trim((string) Configuration::get('PS_SHOP_ADDR1') . ' ' . Configuration::get('PS_SHOP_ADDR2')) . '<br>'
            . Configuration::get('PS_SHOP_CODE') . ' ' . Configuration::get('PS_SHOP_CITY') . '</p>'
            . '<p>Les frais de renvoi restent à votre charge (art. L221-23 du Code de la consommation). '
            . 'Le remboursement intégral (produit + frais de livraison standard) interviendra au plus tard 14 jours '
            . 'après récupération du bien ou réception de la preuve d\'expédition, par le même moyen de paiement.</p>';
    }

    /**
     * Clause type à insérer dans les CGV (obligation d'information sur
     * l'existence et l'emplacement de la fonctionnalité — ordonnance n°2026-2).
     */
    public function getCgvClause($iso = 'fr')
    {
        $url = $this->context->link->getModuleLink($this->name, 'formulaire', []);
        $days = (int) RetractationDelai::getDelaiJours();
        $label = Configuration::get('RETRACTATION_LINK_LABEL');

        switch ($iso) {
            case 'en':
                return "Right of withdrawal — online facility\n\n"
                    . "In accordance with articles L221-18 et seq. of the French Consumer Code, you have a period of $days days to withdraw from your "
                    . "order, starting the day after delivery of the goods (or the day after the conclusion of the contract for services). If this period "
                    . "expires on a Saturday, Sunday or public holiday, it is extended until the next working day.\n\n"
                    . "You may exercise this right free of charge, at any time, directly online via the link « $label » available in the footer of every "
                    . "page of the site and in your customer account (My orders), or at the following address: $url. An acknowledgment of receipt will be "
                    . "sent to you immediately by email with a PDF copy.\n\n"
                    . "The right of withdrawal cannot be exercised for the contracts listed in article L221-28 of the French Consumer Code (in particular "
                    . "goods made to the consumer's specifications or clearly personalised, goods liable to deteriorate or expire rapidly, sealed goods "
                    . "unsealed after delivery which cannot be returned for reasons of health protection or hygiene, etc.).\n\n"
                    . "Return costs are borne by the customer. The refund of all sums paid, including standard delivery costs, will be made no later than "
                    . "14 days after recovery of the goods or receipt of proof of shipment, using the same means of payment as the order.";

            case 'es':
                return "Derecho de desistimiento — funcionalidad en línea\n\n"
                    . "De conformidad con los artículos L221-18 y siguientes del Código de Consumo francés, dispone de un plazo de $days días para "
                    . "desistir de su pedido, a partir del día siguiente a la entrega del bien (o del día siguiente a la celebración del contrato en caso "
                    . "de prestación de servicios). Si este plazo finaliza en sábado, domingo o día festivo, se prorroga hasta el primer día hábil "
                    . "siguiente.\n\n"
                    . "Puede ejercer este derecho de forma gratuita, en cualquier momento, directamente en línea a través del enlace « $label » disponible "
                    . "en el pie de página de todas las páginas del sitio y en su cuenta de cliente (Mis pedidos), o en la siguiente dirección: $url. Se le "
                    . "enviará de inmediato un acuse de recibo por correo electrónico, acompañado de un ejemplar en PDF.\n\n"
                    . "El derecho de desistimiento no puede ejercerse para los contratos enumerados en el artículo L221-28 del Código de Consumo francés "
                    . "(en particular bienes confeccionados conforme a sus especificaciones o claramente personalizados, bienes que puedan deteriorarse o "
                    . "caducar con rapidez, bienes desprecintados tras la entrega que no puedan devolverse por razones de higiene o de protección de la "
                    . "salud, etc.).\n\n"
                    . "Los gastos de devolución corren a cargo del cliente. El reembolso de la totalidad de las cantidades abonadas, incluidos los gastos "
                    . "de envío estándar, se efectuará a más tardar 14 días después de la recuperación del bien o de la recepción de la prueba de envío, "
                    . "mediante el mismo medio de pago utilizado en el pedido.";

            case 'de':
                return "Widerrufsrecht — Online-Funktion\n\n"
                    . "Gemäß den Artikeln L221-18 ff. des französischen Verbrauchergesetzbuchs haben Sie eine Frist von $days Tagen, um Ihre Bestellung "
                    . "zu widerrufen, ab dem Tag nach der Lieferung der Ware (oder dem Tag nach Vertragsabschluss bei Dienstleistungen). Fällt das "
                    . "Fristende auf einen Samstag, Sonntag oder Feiertag, verlängert sich die Frist bis zum nächsten Werktag.\n\n"
                    . "Sie können dieses Recht kostenlos und jederzeit direkt online über den Link « $label » ausüben, der in der Fußzeile jeder Seite der "
                    . "Website sowie in Ihrem Kundenkonto (Meine Bestellungen) verfügbar ist, oder unter folgender Adresse: $url. Eine Empfangsbestätigung "
                    . "wird Ihnen umgehend per E-Mail mit einem PDF-Exemplar zugesandt.\n\n"
                    . "Das Widerrufsrecht kann für die in Artikel L221-28 des französischen Verbrauchergesetzbuchs aufgeführten Verträge nicht ausgeübt "
                    . "werden (insbesondere nach Kundenspezifikation angefertigte oder eindeutig personalisierte Waren, schnell verderbliche oder "
                    . "ablaufende Waren, nach der Lieferung entsiegelte Waren, die aus Gründen des Gesundheitsschutzes oder der Hygiene nicht "
                    . "zurückgesandt werden können, usw.).\n\n"
                    . "Die Rücksendekosten trägt der Kunde. Die Rückerstattung aller gezahlten Beträge einschließlich der Standard-Versandkosten erfolgt "
                    . "spätestens 14 Tage nach Erhalt der Ware oder des Versandnachweises über dasselbe Zahlungsmittel wie bei der Bestellung.";

            case 'it':
                return "Diritto di recesso — funzionalità online\n\n"
                    . "Ai sensi degli articoli L221-18 e seguenti del Codice del consumo francese, dispone di un termine di $days giorni per recedere dal "
                    . "Suo ordine, a partire dal giorno successivo alla consegna del bene (o dal giorno successivo alla conclusione del contratto per una "
                    . "prestazione di servizi). Se tale termine scade di sabato, domenica o in un giorno festivo, è prorogato fino al primo giorno "
                    . "lavorativo successivo.\n\n"
                    . "Può esercitare questo diritto gratuitamente, in qualsiasi momento, direttamente online tramite il link « $label » disponibile nel "
                    . "footer di tutte le pagine del sito e nel Suo account cliente (I miei ordini), oppure al seguente indirizzo: $url. Le verrà inviata "
                    . "immediatamente una conferma di ricezione via e-mail, accompagnata da una copia in PDF.\n\n"
                    . "Il diritto di recesso non può essere esercitato per i contratti elencati all'articolo L221-28 del Codice del consumo francese (in "
                    . "particolare beni confezionati secondo le Sue specifiche o chiaramente personalizzati, beni che rischiano di deteriorarsi o scadere "
                    . "rapidamente, beni sigillati aperti dopo la consegna che non possono essere restituiti per motivi di igiene o di protezione della "
                    . "salute, ecc.).\n\n"
                    . "Le spese di restituzione sono a carico del cliente. Il rimborso della totalità delle somme versate, comprese le spese di spedizione "
                    . "standard, sarà effettuato entro 14 giorni dal recupero del bene o dalla ricezione della prova di spedizione, con lo stesso mezzo di "
                    . "pagamento utilizzato per l'ordine.";

            case 'nl':
                return "Herroepingsrecht — onlinefunctie\n\n"
                    . "Overeenkomstig de artikelen L221-18 e.v. van het Franse consumentenwetboek beschikt u over een termijn van $days dagen om uw "
                    . "bestelling te herroepen, vanaf de dag na de levering van het goed (of de dag na het sluiten van de overeenkomst bij diensten). Als "
                    . "deze termijn afloopt op een zaterdag, zondag of feestdag, wordt hij verlengd tot de eerstvolgende werkdag.\n\n"
                    . "U kunt dit recht kosteloos en op elk moment rechtstreeks online uitoefenen via de link « $label » die beschikbaar is in de "
                    . "voettekst van elke pagina van de site en in uw klantaccount (Mijn bestellingen), of op het volgende adres: $url. Er wordt u "
                    . "onmiddellijk een ontvangstbevestiging per e-mail toegestuurd, vergezeld van een pdf-exemplaar.\n\n"
                    . "Het herroepingsrecht kan niet worden uitgeoefend voor de overeenkomsten genoemd in artikel L221-28 van het Franse "
                    . "consumentenwetboek (met name op maat gemaakte of duidelijk gepersonaliseerde goederen, goederen die snel kunnen bederven of "
                    . "verouderen, na levering ontzegelde goederen die om redenen van gezondheidsbescherming of hygiëne niet kunnen worden teruggestuurd, "
                    . "enz.).\n\n"
                    . "De retourkosten zijn voor rekening van de klant. De terugbetaling van alle betaalde bedragen, inclusief de standaard "
                    . "verzendkosten, vindt plaats uiterlijk 14 dagen na ontvangst van het goed of van het bewijs van verzending, via hetzelfde "
                    . "betaalmiddel als bij de bestelling.";

            case 'pt':
                return "Direito de livre resolução — funcionalidade online\n\n"
                    . "Nos termos dos artigos L221-18 e seguintes do Código do Consumo francês, dispõe de um prazo de $days dias para resolver a sua "
                    . "encomenda, a contar do dia seguinte à entrega do bem (ou do dia seguinte à celebração do contrato no caso de prestação de "
                    . "serviços). Se este prazo terminar a um sábado, domingo ou feriado, é prorrogado até ao primeiro dia útil seguinte.\n\n"
                    . "Pode exercer este direito gratuitamente, a qualquer momento, diretamente online através da ligação « $label » disponível no rodapé "
                    . "de todas as páginas do site e na sua conta de cliente (As minhas encomendas), ou no seguinte endereço: $url. Ser-lhe-á enviado de "
                    . "imediato um aviso de receção por e-mail, acompanhado de um exemplar em PDF.\n\n"
                    . "O direito de livre resolução não pode ser exercido para os contratos enumerados no artigo L221-28 do Código do Consumo francês (em "
                    . "particular bens feitos segundo as suas especificações ou claramente personalizados, bens suscetíveis de se deteriorarem ou "
                    . "expirarem rapidamente, bens dessselados após a entrega que não possam ser devolvidos por razões de higiene ou de proteção da saúde, "
                    . "etc.).\n\n"
                    . "Os custos de devolução ficam a cargo do cliente. O reembolso da totalidade das quantias pagas, incluindo os custos de envio "
                    . "normais, será efetuado no prazo máximo de 14 dias após a receção do bem ou da prova de expedição, através do mesmo meio de "
                    . "pagamento utilizado na encomenda.";

            case 'pl':
                return "Prawo do odstąpienia — funkcja online\n\n"
                    . "Zgodnie z artykułami L221-18 i następnymi francuskiego Kodeksu konsumenckiego przysługuje Państwu termin $days dni na odstąpienie "
                    . "od zamówienia, liczony od dnia następującego po dostawie towaru (lub od dnia następującego po zawarciu umowy w przypadku "
                    . "świadczenia usług). Jeżeli termin ten upływa w sobotę, niedzielę lub dzień świąteczny, zostaje przedłużony do najbliższego dnia "
                    . "roboczego.\n\n"
                    . "Z prawa tego mogą Państwo skorzystać bezpłatnie, w dowolnym momencie, bezpośrednio online za pośrednictwem linku « $label » "
                    . "dostępnego w stopce każdej strony witryny oraz na koncie klienta (Moje zamówienia), lub pod następującym adresem: $url. "
                    . "Potwierdzenie odbioru zostanie wysłane natychmiast e-mailem wraz z egzemplarzem PDF.\n\n"
                    . "Z prawa do odstąpienia nie można skorzystać w przypadku umów wymienionych w artykule L221-28 francuskiego Kodeksu konsumenckiego (w "
                    . "szczególności towarów wykonanych według specyfikacji konsumenta lub wyraźnie spersonalizowanych, towarów, które mogą szybko ulec "
                    . "zepsuciu lub przeterminowaniu, towarów odpieczętowanych po dostawie, których nie można zwrócić ze względu na ochronę zdrowia lub "
                    . "higienę, itp.).\n\n"
                    . "Koszty odesłania ponosi klient. Zwrot wszystkich wpłaconych kwot, w tym standardowych kosztów dostawy, zostanie dokonany "
                    . "najpóźniej w ciągu 14 dni od odzyskania towaru lub otrzymania dowodu wysyłki, tym samym sposobem płatności, który został użyty przy "
                    . "zamówieniu.";

            default:
                return "Droit de rétractation — fonctionnalité en ligne\n\n"
                    . "Conformément aux articles L221-18 et suivants du Code de la consommation, vous disposez d'un délai de $days jours pour vous "
                    . "rétracter de votre commande, à compter du lendemain de la livraison du bien (ou du lendemain de la conclusion du contrat pour une "
                    . "prestation de services). Si ce délai expire un samedi, un dimanche ou un jour férié, il est prolongé jusqu'au premier jour ouvrable "
                    . "suivant.\n\n"
                    . "Vous pouvez exercer ce droit gratuitement, à tout moment, directement en ligne via le lien « $label » accessible en pied de page de "
                    . "toutes les pages du site ainsi que depuis votre espace client (Mes commandes), ou à l'adresse suivante : $url. Un accusé de "
                    . "réception vous sera adressé immédiatement par email, accompagné d'un exemplaire PDF.\n\n"
                    . "Le droit de rétractation ne peut être exercé pour les contrats listés à l'article L221-28 du Code de la consommation (notamment "
                    . "biens confectionnés selon vos spécifications ou nettement personnalisés, biens susceptibles de se détériorer ou de se périmer "
                    . "rapidement, biens descellés après la livraison ne pouvant être renvoyés pour des raisons d'hygiène ou de protection de la santé, "
                    . "etc.).\n\n"
                    . "Les frais de renvoi du bien restent à votre charge. Le remboursement de la totalité des sommes versées, y compris les frais de "
                    . "livraison standard, interviendra au plus tard 14 jours après récupération du bien ou réception de la preuve d'expédition, par le "
                    . "même moyen de paiement que celui utilisé pour la commande.";
        }
    }

    /* ------------------------------------------------------------------ */
    /* Configuration BO                                                    */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitRetractationConfig')) {
            $email = trim((string) Tools::getValue('RETRACTATION_SAV_EMAIL'));
            $days = (int) Tools::getValue('RETRACTATION_DELAY_DAYS');
            if ($email && !Validate::isEmail($email)) {
                $output .= $this->displayError($this->l('Adresse email SAV invalide.'));
            } elseif ($days < 14) {
                $output .= $this->displayError($this->l('Le délai ne peut pas être inférieur au minimum légal de 14 jours.'));
            } else {
                Configuration::updateValue('RETRACTATION_SAV_EMAIL', $email);
                Configuration::updateValue('RETRACTATION_DELAY_DAYS', $days);
                Configuration::updateValue('RETRACTATION_LINK_LABEL', trim((string) Tools::getValue('RETRACTATION_LINK_LABEL')) ?: 'Exercer mon droit de rétractation');
                Configuration::updateValue('RETRACTATION_SHOW_FOOTER_LINK', (int) Tools::getValue('RETRACTATION_SHOW_FOOTER_LINK'));
                Configuration::updateValue('RETRACTATION_CREATE_ORDER_RETURN', (int) Tools::getValue('RETRACTATION_CREATE_ORDER_RETURN'));
                Configuration::updateValue('RETRACTATION_ALLOW_UNDELIVERED', (int) Tools::getValue('RETRACTATION_ALLOW_UNDELIVERED'));
                Configuration::updateValue('RETRACTATION_HIDE_NATIVE_FORM', (int) Tools::getValue('RETRACTATION_HIDE_NATIVE_FORM'));
                Configuration::updateValue('RETRACTATION_EXCLUDED_CATS', $this->sanitizeIdList(Tools::getValue('RETRACTATION_EXCLUDED_CATS')));
                Configuration::updateValue('RETRACTATION_EXCLUDED_PRODUCTS', $this->sanitizeIdList(Tools::getValue('RETRACTATION_EXCLUDED_PRODUCTS')));
                Configuration::updateValue('RETRACTATION_PROCEDURE_TEXT', Tools::getValue('RETRACTATION_PROCEDURE_TEXT'), true);
                $output .= $this->displayConfirmation($this->l('Configuration enregistrée.'));
            }
        }

        if (Tools::isSubmit('submitRetractationMapping')) {
            $delivered = array_map('intval', (array) Tools::getValue('RETRACTATION_DELIVERED_STATES', []));
            $shipped = array_map('intval', (array) Tools::getValue('RETRACTATION_SHIPPED_STATES', []));
            $blocked = array_map('intval', (array) Tools::getValue('RETRACTATION_BLOCKED_STATES', []));
            // Exclusivité : bloquant > livré > expédié.
            $delivered = array_values(array_diff($delivered, $blocked));
            $shipped = array_values(array_diff($shipped, $blocked, $delivered));
            Configuration::updateValue('RETRACTATION_DELIVERED_STATES', implode(',', $delivered));
            Configuration::updateValue('RETRACTATION_SHIPPED_STATES', implode(',', $shipped));
            Configuration::updateValue('RETRACTATION_BLOCKED_STATES', implode(',', $blocked));
            $output .= $this->displayConfirmation($this->l('Mapping des statuts enregistré.'));
        }

        $activeTab = Tools::isSubmit('submitRetractationMapping') ? 'rc-tab-mapping' : 'rc-tab-config';

        return $output . $this->renderTabs($activeTab);
    }

    /**
     * Onglet "Mapping des statuts" : matrice interactive (un statut par ligne,
     * deux rôles en colonnes) + remplissage automatique dynamique.
     */
    protected function renderMappingTab()
    {
        $states = RetractationRequest::getOrderStatesWithMeta();
        $deliveredSel = RetractationRequest::getMappedStates('RETRACTATION_DELIVERED_STATES');
        $shippedSel = RetractationRequest::getMappedStates('RETRACTATION_SHIPPED_STATES');
        $blockedSel = RetractationRequest::getMappedStates('RETRACTATION_BLOCKED_STATES');
        $suggestion = RetractationRequest::suggestStateMapping();

        $roles = [
            'DELIVERED' => ['label' => $this->l('Livré (démarre le délai 14 j)'), 'sel' => $deliveredSel],
            'SHIPPED' => ['label' => $this->l('Expédié (en cours d\'acheminement)'), 'sel' => $shippedSel],
            'BLOCKED' => ['label' => $this->l('Bloquant (aucune rétractation)'), 'sel' => $blockedSel],
        ];

        $action = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $html = '<form method="post" action="' . $action . '" id="rc-mapping-form">';
        $html .= '<div class="alert alert-info">'
            . $this->l('Associez chaque statut de commande à un rôle. « Livré » fait démarrer le délai légal de 14 jours (en complément du drapeau natif de PrestaShop) ; « Bloquant » masque le bouton de rétractation (en plus des états annulé / remboursé / erreur, toujours bloquants). Le remplissage automatique analyse les drapeaux et le nom de chaque statut.')
            . '</div>';

        $html .= '<div class="rc-map-toolbar">'
            . '<button type="button" class="btn btn-default" id="rc-map-auto"><i class="icon-magic"></i> ' . $this->l('Remplissage automatique') . '</button> '
            . '<button type="button" class="btn btn-default" id="rc-map-reset"><i class="icon-eraser"></i> ' . $this->l('Tout décocher') . '</button> '
            . '<input type="text" id="rc-map-search" class="rc-map-search" placeholder="' . $this->l('Filtrer par nom ou ID…') . '"> '
            . '<span id="rc-map-summary" class="rc-map-summary"></span>'
            . '</div>';

        $html .= '<table class="table rc-map-table"><thead><tr><th>' . $this->l('Statut de commande') . '</th>';
        foreach ($roles as $r) {
            $html .= '<th class="rc-map-col">' . $r['label'] . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($states as $s) {
            $id = (int) $s['id_state'];
            $flags = [];
            if ($s['paid']) { $flags[] = 'paid'; }
            if ($s['shipped']) { $flags[] = 'shipped'; }
            if ($s['delivery']) { $flags[] = 'delivery'; }
            $flagsTxt = $flags ? '<span class="rc-map-flags">' . implode(' · ', $flags) . '</span>' : '';

            $html .= '<tr data-search="' . $id . ' ' . Tools::strtolower(htmlspecialchars($s['name'])) . '">'
                . '<td class="rc-map-state">'
                . '<span class="rc-map-dot" style="background:' . htmlspecialchars($s['color']) . '"></span>'
                . '<span class="rc-map-id">#' . $id . '</span> '
                . htmlspecialchars($s['name'])
                . ' <span class="rc-map-count" title="' . $this->l('commandes sur 12 mois') . '">' . (int) $s['count'] . '</span>'
                . $flagsTxt
                . '</td>';
            foreach ($roles as $key => $r) {
                $checked = in_array($id, $r['sel'], true) ? ' rc-on' : '';
                $html .= '<td class="rc-map-col">'
                    . '<button type="button" class="rc-map-cell' . $checked . '" data-role="' . $key . '" data-state="' . $id . '"><i class="icon-check"></i></button>'
                    . '<input type="checkbox" class="rc-map-cb" name="RETRACTATION_' . $key . '_STATES[]" value="' . $id . '"' . ($checked ? ' checked' : '') . ' style="display:none" data-role="' . $key . '" data-state="' . $id . '">'
                    . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="panel-footer"><button type="submit" name="submitRetractationMapping" class="btn btn-default pull-right">'
            . '<i class="process-icon-save"></i> ' . $this->l('Enregistrer') . '</button></div>';
        $html .= '</form>';

        // Suggestion dynamique exposée au JS pour le bouton "Remplissage automatique".
        $html .= '<script type="text/javascript">var rcMapSuggestion = ' . json_encode($suggestion) . ';</script>';
        $html .= $this->renderMappingAssets();

        return $html;
    }

    /**
     * Styles + comportement JS de la matrice de mapping (autonomes, BO).
     */
    protected function renderMappingAssets()
    {
        $txtSummary = $this->l('%mapped% statut(s) mappé(s), %ignored% ignoré(s)');
        $txtConflict = $this->l('%n% conflit(s) : un statut ne peut appartenir qu\'à un seul rôle.');

        return '
<style>
.rc-map-toolbar{margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rc-map-search{height:32px;border:1px solid #ccc;border-radius:4px;padding:0 10px;min-width:220px}
.rc-map-summary{color:#555;font-size:13px;margin-left:auto}
.rc-map-summary .rc-map-conflict{color:#c0392b;font-weight:bold}
.rc-map-table td,.rc-map-table th{vertical-align:middle!important}
.rc-map-col{text-align:center;width:170px}
.rc-map-state{font-size:13px}
.rc-map-dot{display:inline-block;width:11px;height:11px;border-radius:50%;margin-right:6px;vertical-align:middle;border:1px solid rgba(0,0,0,.15)}
.rc-map-id{color:#888;font-weight:600;margin-right:2px}
.rc-map-count{display:inline-block;background:#eef;color:#46a;border-radius:10px;padding:0 8px;font-size:11px;margin-left:6px}
.rc-map-flags{display:inline-block;margin-left:8px;font-size:11px;color:#999}
.rc-map-cell{width:34px;height:34px;border:1px solid #ccd;border-radius:6px;background:#fff;color:transparent;cursor:pointer;transition:all .12s}
.rc-map-cell:hover{border-color:#2e7d32}
.rc-map-cell.rc-on{background:#2e7d32;border-color:#2e7d32;color:#fff}
.rc-map-cell.rc-on[data-role=SHIPPED]{background:#1565c0;border-color:#1565c0}
.rc-map-cell.rc-on[data-role=BLOCKED]{background:#c0392b;border-color:#c0392b}
.rc-map-cell.rc-conflict{box-shadow:0 0 0 3px #e67e22}
</style>
<script type="text/javascript">
(function(){
  var form=document.getElementById("rc-mapping-form");
  if(!form)return;
  function cells(){return form.querySelectorAll(".rc-map-cell");}
  function setCell(btn,on){btn.classList.toggle("rc-on",on);var cb=btn.parentNode.querySelector(".rc-map-cb");if(cb)cb.checked=on;}
  function summary(){
    var rows=form.querySelectorAll("tbody tr"),mapped=0,ignored=0,conflicts=0;
    rows.forEach(function(r){
      var on=r.querySelectorAll(".rc-map-cell.rc-on");
      r.querySelectorAll(".rc-map-cell").forEach(function(c){c.classList.remove("rc-conflict")});
      // Un statut ne doit appartenir qu\'a un seul role.
      if(on.length>1){conflicts++;on.forEach(function(c){c.classList.add("rc-conflict")});}
      if(on.length===0)ignored++;else mapped++;
    });
    var s=' . json_encode($txtSummary) . '.replace("%mapped%",mapped).replace("%ignored%",ignored);
    if(conflicts>0)s+=" — <span class=\"rc-map-conflict\">"+' . json_encode($txtConflict) . '.replace("%n%",conflicts)+"</span>";
    var el=document.getElementById("rc-map-summary");if(el)el.innerHTML=s;
  }
  form.addEventListener("click",function(e){
    var btn=e.target.closest(".rc-map-cell");if(!btn)return;
    e.preventDefault();setCell(btn,!btn.classList.contains("rc-on"));summary();
  });
  var auto=document.getElementById("rc-map-auto");
  if(auto)auto.addEventListener("click",function(){
    if(typeof rcMapSuggestion==="undefined")return;
    cells().forEach(function(btn){
      var role=btn.dataset.role,sid=parseInt(btn.dataset.state,10);
      var on=(rcMapSuggestion[role]||[]).indexOf(sid)>-1;setCell(btn,on);
    });summary();
  });
  var reset=document.getElementById("rc-map-reset");
  if(reset)reset.addEventListener("click",function(){cells().forEach(function(b){setCell(b,false)});summary();});
  var search=document.getElementById("rc-map-search");
  if(search)search.addEventListener("input",function(){
    var q=this.value.toLowerCase().trim();
    form.querySelectorAll("tbody tr").forEach(function(r){
      r.style.display=(!q||(r.dataset.search||"").indexOf(q)>-1)?"":"none";
    });
  });
  summary();
})();
</script>';
    }

    /**
     * Conteneur à onglets : Configuration · Mapping des statuts · Clause CGV.
     */
    protected function renderTabs($activeTab = 'rc-tab-config')
    {
        $tabs = [
            ['id' => 'rc-tab-config', 'label' => $this->l('Configuration'), 'icon' => 'icon-cogs', 'content' => $this->renderConfigForm()],
            ['id' => 'rc-tab-mapping', 'label' => $this->l('Mapping des statuts'), 'icon' => 'icon-sitemap', 'content' => $this->renderMappingTab()],
            ['id' => 'rc-tab-cgv', 'label' => $this->l('Clause CGV'), 'icon' => 'icon-file-text', 'content' => $this->renderCgvPanel()],
        ];

        $nav = '<ul class="nav nav-tabs" id="rc-config-tabs">';
        $panes = '<div class="tab-content" style="padding-top:15px;">';
        foreach ($tabs as $t) {
            $active = $t['id'] === $activeTab ? ' active' : '';
            $nav .= '<li class="' . trim($active) . '"><a href="#' . $t['id'] . '" data-toggle="tab"><i class="' . $t['icon'] . '"></i> ' . $t['label'] . '</a></li>';
            $panes .= '<div class="tab-pane' . $active . '" id="' . $t['id'] . '">' . $t['content'] . '</div>';
        }
        $nav .= '</ul>';
        $panes .= '</div>';

        return '<div class="panel">' . $nav . $panes . '</div>' . $this->renderCredits();
    }

    /**
     * Encart de crédit / branding affiché en bas de la page de configuration.
     * Rappelle l'auteur, la licence et le disclaimer (aide à la conformité,
     * pas un conseil juridique).
     */
    protected function renderCredits()
    {
        return '
        <div class="panel">
            <div class="panel-heading"><i class="icon-leaf"></i> Magic Garden</div>
            <div class="row">
                <div class="col-lg-8">
                    <p>' . $this->l('Module gratuit développé par') . ' <strong>ZM40</strong> / Magic Garden — '
                        . '<a href="https://zm40.com/retractation/" target="_blank" rel="noopener">zm40.com</a>. '
                        . $this->l('Distribué sous licence GPL v3 (libre d\'utilisation, de modification et de redistribution).') . '</p>
                    <p class="text-muted"><small>' . $this->l('Ce module est une aide à la mise en conformité (droit de rétractation, art. L221-18 s. du Code de la consommation) et ne constitue pas un conseil juridique. Le marchand reste seul responsable de la conformité de sa boutique.') . '</small></p>
                    <p>' . $this->l('Besoin d\'aide à l\'installation, d\'une personnalisation ou d\'une garantie de conformité ?') . ' '
                        . '<a href="https://magicgarden.fr" target="_blank" rel="noopener">' . $this->l('Contactez-nous') . '</a>.</p>
                </div>
            </div>
        </div>';
    }

    protected function sanitizeIdList($value)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $value)));

        return implode(',', $ids);
    }

    protected function renderConfigForm()
    {
        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Rétractation — configuration'), 'icon' => 'icon-undo'],
                'description' => $this->l('Le délai légal de 14 jours démarre le lendemain de la livraison du bien (ou de la conclusion du contrat pour un service). S\'il expire un samedi, dimanche ou jour férié, il est prolongé jusqu\'au premier jour ouvrable suivant (art. L221-18 C. conso). L\'ordonnance n°2026-2 impose une fonctionnalité de rétractation en ligne visible et facilement accessible à compter du 19 juin 2026.'),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Afficher le lien en pied de page'),
                        'name' => 'RETRACTATION_SHOW_FOOTER_LINK',
                        'desc' => $this->l('Lien visible en pied de page de toutes les pages du site (recommandé : l\'ordonnance n°2026-2 exige une fonctionnalité visible et facilement accessible). Le lien reste affiché dans l\'espace client même si désactivé ici.'),
                        'values' => [
                            ['id' => 'sfl_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'sfl_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Libellé du lien (footer + espace client)'),
                        'name' => 'RETRACTATION_LINK_LABEL',
                        'desc' => $this->l('Lien menant au formulaire de rétractation (client connecté ou invité).'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Délai de rétractation (jours)'),
                        'name' => 'RETRACTATION_DELAY_DAYS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('14 jours minimum (loi). Vous pouvez proposer un délai plus long, jamais plus court.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Email de notification SAV'),
                        'name' => 'RETRACTATION_SAV_EMAIL',
                        'desc' => $this->l('Chaque nouvelle demande de rétractation est notifiée à cette adresse.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Créer un retour natif PrestaShop'),
                        'name' => 'RETRACTATION_CREATE_ORDER_RETURN',
                        'desc' => $this->l('Recommandé : chaque rétractation crée un retour produit natif (SAV > Retours produits), le client suit son retour dans son espace client.'),
                        'values' => [
                            ['id' => 'cor_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'cor_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Masquer le formulaire de retour natif'),
                        'name' => 'RETRACTATION_HIDE_NATIVE_FORM',
                        'desc' => $this->l('Recommandé : masque le formulaire de retour natif sur le détail de commande pour que la rétractation passe uniquement par le parcours légal du module (un seul point d\'entrée). Le suivi "Mes retours de marchandise" reste actif.'),
                        'values' => [
                            ['id' => 'hnf_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'hnf_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Autoriser avant livraison'),
                        'name' => 'RETRACTATION_ALLOW_UNDELIVERED',
                        'desc' => $this->l('Conforme à la loi : le consommateur peut se rétracter dès la conclusion du contrat, avant même la livraison (art. L221-18 dernier al.).'),
                        'values' => [
                            ['id' => 'au_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'au_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Catégories exclues (IDs)'),
                        'name' => 'RETRACTATION_EXCLUDED_CATS',
                        'desc' => $this->l('IDs de catégories séparés par des virgules — produits exclus du droit de rétractation (sur mesure, périssables, hygiène… art. L221-28). Le bouton est masqué si TOUTE la commande est exclue ; sinon une alerte est affichée au SAV.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Produits exclus (IDs)'),
                        'name' => 'RETRACTATION_EXCLUDED_PRODUCTS',
                        'desc' => $this->l('IDs de produits séparés par des virgules.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Procédure de retour (email d\'acceptation)'),
                        'name' => 'RETRACTATION_PROCEDURE_TEXT',
                        'autoload_rte' => true,
                        'desc' => $this->l('Texte envoyé au client lorsque le SAV valide la demande (adresse de retour, consignes, remboursement).'),
                    ],
                ],
                'submit' => ['title' => $this->l('Enregistrer')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitRetractationConfig';
        $helper->fields_value = [
            'RETRACTATION_SHOW_FOOTER_LINK' => Tools::getValue('RETRACTATION_SHOW_FOOTER_LINK', Configuration::get('RETRACTATION_SHOW_FOOTER_LINK')),
            'RETRACTATION_LINK_LABEL' => Tools::getValue('RETRACTATION_LINK_LABEL', Configuration::get('RETRACTATION_LINK_LABEL')),
            'RETRACTATION_DELAY_DAYS' => Tools::getValue('RETRACTATION_DELAY_DAYS', Configuration::get('RETRACTATION_DELAY_DAYS')),
            'RETRACTATION_SAV_EMAIL' => Tools::getValue('RETRACTATION_SAV_EMAIL', Configuration::get('RETRACTATION_SAV_EMAIL')),
            'RETRACTATION_CREATE_ORDER_RETURN' => Tools::getValue('RETRACTATION_CREATE_ORDER_RETURN', Configuration::get('RETRACTATION_CREATE_ORDER_RETURN')),
            'RETRACTATION_HIDE_NATIVE_FORM' => Tools::getValue('RETRACTATION_HIDE_NATIVE_FORM', Configuration::get('RETRACTATION_HIDE_NATIVE_FORM')),
            'RETRACTATION_ALLOW_UNDELIVERED' => Tools::getValue('RETRACTATION_ALLOW_UNDELIVERED', Configuration::get('RETRACTATION_ALLOW_UNDELIVERED')),
            'RETRACTATION_EXCLUDED_CATS' => Tools::getValue('RETRACTATION_EXCLUDED_CATS', Configuration::get('RETRACTATION_EXCLUDED_CATS')),
            'RETRACTATION_EXCLUDED_PRODUCTS' => Tools::getValue('RETRACTATION_EXCLUDED_PRODUCTS', Configuration::get('RETRACTATION_EXCLUDED_PRODUCTS')),
            'RETRACTATION_PROCEDURE_TEXT' => Tools::getValue('RETRACTATION_PROCEDURE_TEXT', Configuration::get('RETRACTATION_PROCEDURE_TEXT')),
        ];

        return $helper->generateForm([$form]);
    }

    /**
     * Panneau "clause CGV" prête à copier (FR + EN) — l'ordonnance impose
     * d'informer le consommateur dans les CGV de l'existence et de
     * l'emplacement de la fonctionnalité de rétractation en ligne.
     */
    protected function renderCgvPanel()
    {
        // Une clause par langue installée dans la boutique (repli français si
        // la langue n'est pas couverte par le module).
        $supported = ['fr', 'en', 'es', 'de', 'it', 'nl', 'pt', 'pl'];
        $names = [
            'fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'de' => 'Deutsch',
            'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português', 'pl' => 'Polski',
        ];

        $isoList = [];
        foreach (Language::getLanguages(true) as $lang) {
            $iso = in_array($lang['iso_code'], $supported, true) ? $lang['iso_code'] : 'fr';
            $isoList[$iso] = $names[$iso];
        }
        if (!$isoList) {
            $isoList = ['fr' => 'Français'];
        }

        $cols = '';
        foreach ($isoList as $iso => $name) {
            $clause = htmlspecialchars($this->getCgvClause($iso));
            $cols .= '
                <div class="col-lg-6">
                    <h4>' . $name . '</h4>
                    <textarea readonly rows="14" class="form-control" onclick="this.select();">' . $clause . '</textarea>
                </div>';
        }

        return '
        <div class="panel">
            <div class="panel-heading"><i class="icon-file-text"></i> ' . $this->l('Clause CGV prête à copier (obligation d\'information — ordonnance n°2026-2)') . '</div>
            <div class="alert alert-warning">' . $this->l('Installer la fonctionnalité ne suffit pas : vos CGV doivent mentionner son existence et son emplacement. Copiez la clause ci-dessous dans vos CGV (une version par langue de la boutique) et adaptez-la si certains de vos produits relèvent des exceptions de l\'article L221-28.') . '</div>
            <div class="row">' . $cols . '
            </div>
        </div>';
    }

    /* ------------------------------------------------------------------ */
    /* Front office                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Routes propres pour les contrôleurs front du module. Sans elles,
     * Link::getModuleLink ne trouve pas la route nommée
     * "module-retractationcommande-formulaire" et génère une URL en
     * index.php?controller=... qui aboutit à une page 404.
     */
    public function hookModuleRoutes($params)
    {
        return [
            'module-retractationcommande-formulaire' => [
                'controller' => 'formulaire',
                'rule' => 'retractation',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'retractationcommande',
                    'controller' => 'formulaire',
                ],
            ],
            'module-retractationcommande-demande' => [
                'controller' => 'demande',
                'rule' => 'retractation/demande',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'retractationcommande',
                    'controller' => 'demande',
                ],
            ],
        ];
    }

    /**
     * Injecte JS/CSS + la carte des commandes éligibles sur l'historique des
     * commandes, le détail de commande et la page formulaire du module.
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        $controller = $this->context->controller;
        $pages = ['history', 'order-detail', 'module-retractationcommande-formulaire'];
        if (!isset($controller->php_self) || !in_array($controller->php_self, $pages, true)) {
            return;
        }

        $controller->registerStylesheet('retractation-front', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 150]);
        $controller->registerJavascript('retractation-front', 'modules/' . $this->name . '/views/js/front.js', ['position' => 'bottom', 'priority' => 150]);

        $isLogged = $this->context->customer->isLogged();
        $maps = $isLogged
            ? $this->getOrdersMaps((int) $this->context->customer->id)
            : ['eligible' => [], 'statuses' => []];

        Media::addJsDef([
            'retractationConfig' => [
                'ajaxUrl' => $this->context->link->getModuleLink($this->name, 'demande', []),
                'orders' => $maps['eligible'],
                'statuses' => $maps['statuses'],
                'hideNativeForm' => (bool) Configuration::get('RETRACTATION_HIDE_NATIVE_FORM'),
                'labels' => [
                    'button' => $this->l('Se rétracter'),
                    'loading' => $this->l('Chargement…'),
                    'error' => $this->l('Une erreur est survenue. Merci de réessayer ou de contacter le service client.'),
                    'pending' => $this->getStatusLabel(RetractationRequest::STATUS_PENDING),
                    'success_title' => $this->l('Rétractation enregistrée'),
                    'download_pdf' => $this->l('Télécharger l\'accusé de réception (PDF)'),
                ],
            ],
        ]);
    }

    /**
     * Libellé client d'un statut de demande (affiché sur la ligne de commande).
     */
    public function getStatusLabel($status)
    {
        switch ($status) {
            case RetractationRequest::STATUS_PENDING:
                return $this->l('Rétractation en cours de vérification');
            case RetractationRequest::STATUS_ACCEPTED:
                return $this->l('Rétractation validée — retour en cours');
            case RetractationRequest::STATUS_REFUNDED:
                return $this->l('Rétractation remboursée');
            default:
                return '';
        }
    }

    /**
     * Lien "Exercer mon droit de rétractation" en pied de page de toutes les
     * pages (obligation de visibilité — ordonnance n°2026-2).
     */
    public function hookDisplayFooter($params)
    {
        if (!Configuration::get('RETRACTATION_SHOW_FOOTER_LINK')) {
            return '';
        }
        // Lien réservé aux clients connectés.
        if (!$this->context->customer->isLogged()) {
            return '';
        }
        $this->context->smarty->assign([
            'retractation_link_label' => Configuration::get('RETRACTATION_LINK_LABEL'),
            'retractation_link_url' => $this->context->link->getModuleLink($this->name, 'formulaire', []),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/footer-link.tpl');
    }

    /**
     * Lien dans l'espace client (page "Votre compte").
     */
    public function hookDisplayCustomerAccount($params)
    {
        $this->context->smarty->assign([
            'retractation_link_label' => Configuration::get('RETRACTATION_LINK_LABEL'),
            'retractation_link_url' => $this->context->link->getModuleLink($this->name, 'formulaire', []),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/customer-account.tpl');
    }

    /**
     * Encart serveur sur la page de détail de commande.
     */
    public function hookDisplayOrderDetail($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return '';
        }
        /** @var Order $order */
        $order = $params['order'];
        $eligibility = RetractationRequest::getOrderEligibility($order);

        $existing = RetractationRequest::getByOrder((int) $order->id);
        $this->context->smarty->assign([
            'retractation_eligible' => $eligibility['eligible'],
            'retractation_deadline_text' => $eligibility['deadline_text'],
            'retractation_id_order' => (int) $order->id,
            'retractation_token' => $this->getOrderToken($order),
            'retractation_existing' => $existing,
            'retractation_existing_label' => $existing ? $this->getStatusLabel($existing['status']) : '',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order-detail.tpl');
    }

    /**
     * Cartes pour le JS de la page "Mes commandes" :
     *  - eligible : id_order => infos bouton "Se rétracter" ;
     *  - statuses : id_order => libellé de la demande active (badge).
     *
     * @return array{eligible: array, statuses: array}
     */
    public function getOrdersMaps($idCustomer)
    {
        $eligible = [];
        $statuses = [];
        foreach (Order::getCustomerOrders($idCustomer) as $row) {
            $order = new Order((int) $row['id_order']);
            if (!Validate::isLoadedObject($order)) {
                continue;
            }

            $existing = RetractationRequest::getByOrder((int) $order->id);
            if ($existing) {
                $statuses[(int) $order->id] = $this->getStatusLabel($existing['status']);
            }

            $eligibility = RetractationRequest::getOrderEligibility($order);
            if ($eligibility['eligible']) {
                $eligible[(int) $order->id] = [
                    'reference' => $order->reference,
                    'deadline' => $eligibility['deadline_text'],
                    'token' => $this->getOrderToken($order),
                ];
            }
        }

        return ['eligible' => $eligible, 'statuses' => $statuses];
    }

    /**
     * Langue d'envoi des emails : celle de la commande si un dossier
     * mails/<iso>/ existe, sinon anglais, sinon langue par défaut.
     */
    public static function getMailLangId($idLang)
    {
        $dir = _PS_MODULE_DIR_ . 'retractationcommande/mails/';
        $iso = Language::getIsoById((int) $idLang);
        if ($iso && is_dir($dir . $iso)) {
            return (int) $idLang;
        }
        $en = (int) Language::getIdByIso('en');
        if ($en && is_dir($dir . 'en')) {
            return $en;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * Jeton anti-CSRF par commande, partagé avec les front controllers.
     * Dérivé de la clé secrète du client : ne peut être forgé, et permet
     * aussi le parcours invité (délivré après vérification email + référence).
     */
    public function getOrderToken(Order $order)
    {
        $customer = new Customer((int) $order->id_customer);

        return Tools::hash('retractation/' . (int) $order->id . '/' . $customer->secure_key);
    }
}
