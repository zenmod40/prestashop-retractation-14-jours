<?php
/**
 * Upgrade 1.6.1 — les retours natifs créés par le module recopiaient le motif du
 * client tel quel dans OrderReturn.question, que le back-office affiche sans
 * échappement (RC-01). On neutralise le motif des retours déjà créés : seule la
 * partie après « Motif du client : » vient du client. Ajoute aussi la référence de
 * commande à la liste des retours natifs. Idempotent.
 *
 * @param Module $module
 * @return bool
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_1($module)
{
    // Référence de commande dans SAV > Retours produits.
    $module->registerHook('actionAdminReturnListingFieldsModifier');

    $db = Db::getInstance();
    $rows = $db->executeS('
        SELECT orr.`id_order_return`, orr.`question`
        FROM `' . _DB_PREFIX_ . 'order_return` orr
        INNER JOIN `' . _DB_PREFIX_ . 'retractation_request` rr ON rr.`id_order_return` = orr.`id_order_return`');
    if ($rows === false) {
        return false;
    }

    $marker = '<br>Motif du client : ';
    foreach ($rows as $row) {
        $question = (string) $row['question'];
        $pos = strpos($question, $marker);
        if ($pos === false) {
            continue;
        }
        $motif = substr($question, $pos + strlen($marker));
        // Déjà neutralisé (texte sans balise ni entité à décoder) : rien à faire.
        $clean = htmlspecialchars(strip_tags(html_entity_decode($motif, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8');
        if ($clean === $motif) {
            continue;
        }
        $db->update(
            'order_return',
            ['question' => pSQL(substr($question, 0, $pos) . $marker . $clean, true)],
            '`id_order_return` = ' . (int) $row['id_order_return']
        );
    }

    return true;
}
