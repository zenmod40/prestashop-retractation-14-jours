<?php
/**
 * Page "Exercer mon droit de rétractation" (lien footer — ordonnance n°2026-2).
 *
 * Étape 1 — identification :
 *  - client connecté : liste de ses commandes éligibles ;
 *  - invité : email + référence de commande.
 * Étape 2 — confirmation : la modal du module (formulaire type prérempli),
 * via le contrôleur "demande" et le jeton délivré ici.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationDelai.php';
require_once _PS_MODULE_DIR_ . 'retractationcommande/classes/RetractationRequest.php';

class RetractationCommandeFormulaireModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();
        // PrestaShop ne renseigne pas php_self pour les contrôleurs de module :
        // indispensable pour que le hook actionFrontControllerSetMedia charge
        // le JS/CSS de la modal sur cette page.
        $this->php_self = 'module-retractationcommande-formulaire';
    }

    public function initContent()
    {
        parent::initContent();

        $isLogged = $this->context->customer->isLogged();
        $guestOrder = null;
        $guestError = null;
        // Jeu de règles du client connecté (groupe par défaut) ; jeu par défaut
        // pour un visiteur (le parcours invité ne connaît pas encore le client).
        $rule = RetractationRules::forContext();

        // Parcours invité : vérification email + référence de commande.
        if (!$isLogged && Tools::isSubmit('submitGuestSearch')) {
            $result = $this->findGuestOrder(
                trim((string) Tools::getValue('guest_email')),
                trim((string) Tools::getValue('guest_reference'))
            );
            if (is_string($result)) {
                $guestError = $result;
            } else {
                $guestOrder = $result;
                // La commande désigne son client : ses règles priment sur
                // celles du visiteur anonyme.
                $rule = RetractationRules::forOrder(new Order((int) $guestOrder['id_order']));
            }
        }

        $this->context->smarty->assign([
            'rc_is_logged' => $isLogged,
            'rc_orders' => $isLogged ? $this->getCustomerOrdersList($rule['legal']) : [],
            'rc_guest_order' => $guestOrder,
            'rc_guest_error' => $guestError,
            'rc_guest_email' => Tools::getValue('guest_email', ''),
            'rc_guest_reference' => Tools::getValue('guest_reference', ''),
            'rc_delay_days' => $rule['days'],
            'rc_legal' => $rule['legal'],
            'rc_rule' => $rule,
            'rc_login_url' => $this->context->link->getPageLink('authentication', true, null,
                'back=' . urlencode($this->context->link->getModuleLink('retractationcommande', 'formulaire', []))),
        ]);

        $this->setTemplate('module:retractationcommande/views/templates/front/formulaire.tpl');
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $rule = RetractationRules::forContext();
        $breadcrumb['links'][] = [
            'title' => $rule['link_label'] ?: ($rule['legal'] ? $this->module->l('Droit de rétractation', 'formulaire') : $rule['form_title']),
            'url' => $this->context->link->getModuleLink('retractationcommande', 'formulaire', []),
        ];

        return $breadcrumb;
    }

    /**
     * Commandes du client connecté affichables sur la page : uniquement
     * celles encore dans la fenêtre légale (bouton) ou ayant une demande
     * de rétractation en cours (badge de suivi). Les commandes hors délai
     * ou non concernées ne sont pas listées.
     */
    protected function getCustomerOrdersList($legal = true)
    {
        $list = [];
        foreach (Order::getCustomerOrders((int) $this->context->customer->id) as $row) {
            $order = new Order((int) $row['id_order']);
            if (!Validate::isLoadedObject($order)) {
                continue;
            }
            $eligibility = RetractationRequest::getOrderEligibility($order);
            $existing = RetractationRequest::getByOrder((int) $order->id);
            if (!$eligibility['eligible'] && !$existing) {
                continue;
            }
            $list[] = [
                'id_order' => (int) $order->id,
                'reference' => $order->reference,
                'date' => Tools::displayDate($order->date_add),
                'total' => RetractationRequest::formatPrice($order->total_paid_tax_incl, new Currency((int) $order->id_currency)),
                'eligible' => $eligibility['eligible'],
                'deadline_text' => $eligibility['deadline_text'],
                'status_label' => $existing ? $this->module->getStatusLabel($existing['status'], $legal) : '',
                'token' => $this->module->getOrderToken($order),
            ];
        }

        return $list;
    }

    /**
     * Recherche d'une commande en parcours invité.
     *
     * @return array|string données commande ou message d'erreur
     */
    protected function findGuestOrder($email, $reference)
    {
        $genericError = $this->module->l('Aucune commande ne correspond à ces informations. Vérifiez l\'adresse email et la référence (présentes sur votre email de confirmation de commande).', 'formulaire');

        if (!Validate::isEmail($email) || !Validate::isReference($reference)) {
            return $genericError;
        }

        /** @var Order|false $order */
        $order = Order::getByReference($reference)->getFirst();
        if (!$order || !Validate::isLoadedObject($order)) {
            return $genericError;
        }

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)
            || Tools::strtolower($customer->email) !== Tools::strtolower($email)) {
            // Message générique : ne pas révéler l'existence d'une référence.
            return $genericError;
        }

        $eligibility = RetractationRequest::getOrderEligibility($order);
        $existing = RetractationRequest::getByOrder((int) $order->id);

        return [
            'id_order' => (int) $order->id,
            'reference' => $order->reference,
            'date' => Tools::displayDate($order->date_add),
            'eligible' => $eligibility['eligible'],
            'deadline_text' => $eligibility['deadline_text'],
            'reason' => $eligibility['reason'],
            'status_label' => $existing ? $this->module->getStatusLabel($existing['status']) : '',
            'token' => $this->module->getOrderToken($order),
        ];
    }
}
