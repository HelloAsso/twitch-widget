<?php

namespace App\Services;

/**
 * Sur Clever Cloud, l'addon MySQL lié injecte ses propres variables (MYSQL_ADDON_*)
 * et la plateforme expose le commit déployé (COMMIT_ID).
 * On s'en sert quand les variables de l'application ne sont pas définies explicitement.
 */
class CleverCloudEnv
{
    private const FALLBACK_VARS = [
        'DBURL' => 'MYSQL_ADDON_HOST',
        'DBPORT' => 'MYSQL_ADDON_PORT',
        'DBNAME' => 'MYSQL_ADDON_DB',
        'DBUSER' => 'MYSQL_ADDON_USER',
        'DBPASSWORD' => 'MYSQL_ADDON_PASSWORD',
        'APP_VERSION' => 'COMMIT_ID',
    ];

    public static function load(): void
    {
        foreach (self::FALLBACK_VARS as $var => $cleverVar) {
            $cleverValue = $_SERVER[$cleverVar] ?? getenv($cleverVar);
            if (empty($_SERVER[$var]) && !empty($cleverValue)) {
                $_SERVER[$var] = $cleverValue;
            }
        }
    }
}
