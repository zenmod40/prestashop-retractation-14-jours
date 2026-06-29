<?php
/**
 * Photos jointes par le client à une demande de rétractation (état du produit
 * et de son packaging). Facultatif et jamais bloquant.
 *
 * Sécurité : chaque fichier est validé par le système natif PrestaShop
 * (ImageManager::isRealImage / isCorrectImageFileExt, qui inspectent le contenu
 * réel via getimagesize) PUIS RE-ENCODÉ par GD (ImageManager::resize). Le
 * re-encodage reconstruit l'image à partir de ses pixels : tout contenu
 * malveillant éventuellement embarqué (script en EXIF, fichier polyglotte
 * image+PHP, etc.) est détruit. Aucun SVG accepté (vecteur XSS). Les fichiers
 * sont stockés hors d'atteinte directe (.htaccess Deny) et servis uniquement
 * via le contrôleur d'administration, après contrôle.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RetractationPhoto
{
    const MAX_FILES = 4;
    const MAX_SIZE = 4194304; // 4 Mo
    const MAX_DIM = 2000;     // borne le re-encodage
    const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Traite l'upload multiple ($_FILES[$field]) d'une demande : valide,
     * re-encode et stocke chaque image. Retourne la liste des noms de fichiers
     * réellement enregistrés (jamais d'exception : un fichier invalide est
     * simplement ignoré — l'upload n'est jamais bloquant).
     *
     * @param string $field nom du champ de formulaire (input multiple)
     * @param int $idRequest
     * @return array<int, string> noms de fichiers stockés
     */
    public static function storeUploaded($field, $idRequest)
    {
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
            return [];
        }

        $files = $_FILES[$field];
        // Normalise vers un format "liste" (input simple OU multiple).
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

        $dir = self::getStorageDir();
        $saved = [];

        for ($i = 0, $n = count($names); $i < $n; $i++) {
            if (count($saved) >= self::MAX_FILES) {
                break;
            }
            if ((int) $errs[$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string) $tmps[$i];
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            if ((int) $sizes[$i] <= 0 || (int) $sizes[$i] > self::MAX_SIZE) {
                continue;
            }

            $ext = strtolower(pathinfo((string) $names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                continue;
            }

            // Validation native PrestaShop : contenu réellement image + extension cohérente.
            if (!class_exists('ImageManager')) {
                continue;
            }
            if (!ImageManager::isCorrectImageFileExt((string) $names[$i], self::ALLOWED_EXT)) {
                continue;
            }
            // isRealImage inspecte le contenu réel (getimagesize) : rejette tout
            // ce qui n'est pas une vraie image, quelle que soit l'extension.
            if (!ImageManager::isRealImage($tmp)) {
                continue;
            }

            // Re-encodage GD : reconstruit l'image depuis ses pixels (purge tout payload).
            $outExt = ($ext === 'jpeg') ? 'jpg' : $ext;
            $filename = sprintf('retract_%d_%s.%s', (int) $idRequest, Tools::passwdGen(20, 'ALPHANUMERIC'), $outExt);
            $dest = $dir . $filename;

            if (ImageManager::resize($tmp, $dest, self::MAX_DIM, self::MAX_DIM, $outExt)) {
                @chmod($dest, 0644);
                $saved[] = $filename;
            }
        }

        return $saved;
    }

    /**
     * Chemin absolu d'une photo existante, null sinon.
     */
    public static function getPath($filename)
    {
        $filename = basename((string) $filename);
        $path = self::getStorageDir() . $filename;

        return ($filename && file_exists($path)) ? $path : null;
    }

    /**
     * Supprime les photos d'une demande (liste de noms de fichiers).
     */
    public static function deleteMany(array $filenames)
    {
        foreach ($filenames as $f) {
            $path = self::getPath($f);
            if ($path) {
                @unlink($path);
            }
        }
    }

    protected static function getStorageDir()
    {
        $dir = _PS_MODULE_DIR_ . 'retractationcommande/photos/';
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
