<?php
/**
 * Génération de l'accusé de réception PDF de la rétractation
 * (art. L221-21 al. 3 : accusé de réception obligatoire quand la
 * rétractation est déposée via le site du professionnel).
 *
 * Utilise TCPDF embarqué par PrestaShop. Les PDF sont stockés dans
 * modules/retractationcommande/pdf/ (accès direct bloqué par .htaccess),
 * servis via le front controller après contrôle de propriété.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RetractationPdf
{
    /**
     * Génère le PDF et retourne le nom de fichier créé.
     *
     * @param string $html contenu HTML de l'accusé (template smarty rendu)
     * @param int $idRequest
     * @param string|null $appendHtml page 2 facultative (rappel des droits).
     *                    Un PDF multi-pages a aussi l'avantage d'être affiché
     *                    en icône de pièce jointe par Apple Mail, au lieu
     *                    d'être prévisualisé dans le corps du message.
     *
     * @return string|null nom de fichier, null si TCPDF indisponible
     */
    public static function generate($html, $idRequest, $appendHtml = null)
    {
        if (!class_exists('TCPDF')) {
            return null;
        }

        $dir = self::getStorageDir();
        $filename = sprintf('accuse_retractation_%d_%s.pdf', (int) $idRequest, Tools::passwdGen(12, 'ALPHANUMERIC'));

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator(Configuration::get('PS_SHOP_NAME'));
        $pdf->SetAuthor(Configuration::get('PS_SHOP_NAME'));
        $pdf->SetTitle('Accusé de réception — rétractation');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        if ($appendHtml) {
            $pdf->AddPage();
            $pdf->writeHTML($appendHtml, true, false, true, false, '');
        }
        $pdf->Output($dir . $filename, 'F');

        return $filename;
    }

    /**
     * Chemin absolu d'un PDF existant, null sinon.
     */
    public static function getPath($filename)
    {
        $filename = basename((string) $filename);
        $path = self::getStorageDir() . $filename;

        return ($filename && file_exists($path)) ? $path : null;
    }

    protected static function getStorageDir()
    {
        $dir = _PS_MODULE_DIR_ . 'retractationcommande/pdf/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($dir . '.htaccess')) {
            // Accès direct bloqué — compatible Apache 2.4 (Require) et 2.2 (Order/Deny).
            file_put_contents(
                $dir . '.htaccess',
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }
        if (!file_exists($dir . 'index.php')) {
            file_put_contents($dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        return $dir;
    }
}
