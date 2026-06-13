<?php
/**
 * Calcul du délai légal de rétractation (art. L221-18 Code de la consommation).
 *
 * Règles appliquées :
 *  - 14 jours calendaires, décompte démarrant le LENDEMAIN de la livraison du
 *    bien (ou de la conclusion du contrat pour un service) ;
 *  - le dernier jour est donc livraison + 14 jours, jusqu'à 23:59:59 ;
 *  - si ce dernier jour tombe un samedi, un dimanche ou un jour férié
 *    français, le délai est prolongé jusqu'au premier jour ouvrable suivant ;
 *  - le droit peut être exercé dès la conclusion du contrat, avant livraison.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RetractationDelai
{
    const DELAI_JOURS = 14; // minimum légal — non réductible

    /**
     * Durée du délai en jours : 14 minimum (loi), extensible par le marchand
     * via la configuration (un délai plus long est toujours permis).
     */
    public static function getDelaiJours()
    {
        $days = (int) Configuration::get('RETRACTATION_DELAY_DAYS');

        return max(self::DELAI_JOURS, $days);
    }

    /**
     * Date limite légale de rétractation à partir de la date de livraison.
     *
     * @param string|DateTime $deliveryDate
     *
     * @return DateTime fin du délai (dernier jour à 23:59:59)
     */
    public static function getDeadline($deliveryDate)
    {
        $start = ($deliveryDate instanceof DateTime) ? clone $deliveryDate : new DateTime($deliveryDate);
        $deadline = clone $start;
        // Décompte à partir du lendemain : J+1 … J+N => dernier jour = J + N
        $deadline->modify('+' . self::getDelaiJours() . ' days');

        while (self::isNonWorkingDay($deadline)) {
            $deadline->modify('+1 day');
        }
        $deadline->setTime(23, 59, 59);

        return $deadline;
    }

    /**
     * Samedi, dimanche ou jour férié français ?
     */
    public static function isNonWorkingDay(DateTime $date)
    {
        $dow = (int) $date->format('N'); // 6 = samedi, 7 = dimanche
        if ($dow >= 6) {
            return true;
        }

        return in_array($date->format('Y-m-d'), self::getFrenchHolidays((int) $date->format('Y')), true);
    }

    /**
     * Jours fériés français (métropole) pour une année donnée.
     *
     * @return string[] dates au format Y-m-d
     */
    public static function getFrenchHolidays($year)
    {
        static $cache = [];
        if (isset($cache[$year])) {
            return $cache[$year];
        }

        $easter = self::getEasterDate($year);
        $easterMonday = (clone $easter)->modify('+1 day');
        $ascension = (clone $easter)->modify('+39 days');
        $whitMonday = (clone $easter)->modify('+50 days');

        $cache[$year] = [
            $year . '-01-01', // Jour de l'an
            $easterMonday->format('Y-m-d'), // Lundi de Pâques
            $year . '-05-01', // Fête du travail
            $year . '-05-08', // Victoire 1945
            $ascension->format('Y-m-d'), // Ascension
            $whitMonday->format('Y-m-d'), // Lundi de Pentecôte
            $year . '-07-14', // Fête nationale
            $year . '-08-15', // Assomption
            $year . '-11-01', // Toussaint
            $year . '-11-11', // Armistice 1918
            $year . '-12-25', // Noël
        ];

        return $cache[$year];
    }

    /**
     * Dimanche de Pâques (algorithme de Meeus/Butcher, calendrier grégorien),
     * sans dépendre de l'extension PHP calendar.
     */
    public static function getEasterDate($year)
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
