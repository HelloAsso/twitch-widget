<?php

namespace App\Services;

/**
 * Liens vers les pages HelloAsso, partagés par les vues Twig et les emails.
 */
final class HelloAssoLinks
{
    /** Article du centre d'aide qui explique le fonctionnement de HelloAsso Stream. */
    public const HELP_CENTER = 'https://centredaide.helloasso.com/association?question=comment-fonctionne-le-connecteur-stream-caritatif-de-helloasso';

    /** Redirige vers l'espace d'administration HelloAsso de l'utilisateur connecté. */
    public const ACCOUNT = 'https://www.helloasso.com/Redirect.aspx?ref=helloasso-admin';

    public const LEGAL_NOTICE = 'https://www.helloasso.com/mentions-legales';

    /** Wordmark officiel (SVG noir) servi par le CDN HelloAsso. */
    public const WORDMARK_SVG = 'https://cdn.helloasso.com/images/charte/HelloAsso-dark.svg';

    /**
     * @return array<string, string> variables globales Twig
     */
    public static function asTwigGlobals(): array
    {
        return [
            'helpCenterUrl' => self::HELP_CENTER,
            'helloassoAccountUrl' => self::ACCOUNT,
            'legalNoticeUrl' => self::LEGAL_NOTICE,
            'wordmarkUrl' => self::WORDMARK_SVG,
        ];
    }
}
