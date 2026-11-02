<?php

namespace App\Services;

/**
 * Sur Clever Cloud, l'addon MySQL lié injecte ses propres variables (MYSQL_ADDON_*).
 * On s'en sert quand les variables DB* ne sont pas définies explicitement.
 */
class CleverCloudEnv
{
    private const MYSQL_ADDON_VARS = [
        'DBURL' => 'MYSQL_ADDON_HOST',
        'DBPORT' => 'MYSQL_ADDON_PORT',
        'DBNAME' => 'MYSQL_ADDON_DB',
        'DBUSER' => 'MYSQL_ADDON_USER',
        'DBPASSWORD' => 'MYSQL_ADDON_PASSWORD',
    ];

    public static function load(): void
    {
        foreach (self::MYSQL_ADDON_VARS as $var => $addonVar) {
            $addonValue = $_SERVER[$addonVar] ?? getenv($addonVar);
            if (empty($_SERVER[$var]) && !empty($addonValue)) {
                $_SERVER[$var] = $addonValue;
            }
        }
    }
}
