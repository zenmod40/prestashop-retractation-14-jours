<?php
/**
 * Upgrade 1.6.0 — table d'historique des changements d'état des demandes
 * (gestes SAV depuis le back-office ou Régie). Les demandes existantes ne
 * sont pas touchées. Idempotent.
 *
 * @param Module $module
 * @return bool
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0($module)
{
    return RetractationCommande::createHistoryTable();
}
