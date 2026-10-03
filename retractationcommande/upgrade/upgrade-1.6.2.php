<?php
/**
 * Upgrade 1.6.2 — option « Joindre un bon de retour PDF », activée pour
 * les installations existantes (comportement inchangé). Idempotent.
 *
 * @param Module $module
 * @return bool
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_2($module)
{
    if (Configuration::get('RETRACTATION_RETURN_SLIP') === false) {
        return Configuration::updateValue('RETRACTATION_RETURN_SLIP', 1);
    }

    return true;
}
