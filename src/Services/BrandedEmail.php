<?php

namespace App\Services;

/**
 * Gabarit HTML des emails transactionnels, à la charte HelloAsso.
 *
 * Les clients mail ne chargent pas les polices web de la charte : on applique les substitutions prévues
 * par le brandbook pour les polices système (Impact pour les titres, Arial pour le texte).
 */
final class BrandedEmail
{
    private const NOIR = '#101010';
    private const GRIS_FONCE = '#434649';
    private const GRIS_CLAIR = '#CDCED0';
    private const GRIS_TRES_CLAIR = '#F5F5F5';
    private const VERT = '#4A8100';
    private const FONT_TITLE = "'ITC Franklin Gothic', Impact, 'Arial Narrow', sans-serif";
    private const FONT_TEXT = "'ABC Diatype Rounded', Arial, Helvetica, sans-serif";

    /**
     * Construit l'email complet.
     *
     * @param string $baseUrl     domaine de l'app (WEBSITE_DOMAIN), pour l'image du wordmark
     * @param string $title       titre de l'email (affiché en capitales)
     * @param string $contentHtml corps de l'email, construit avec paragraph(), button(), subtitle()…
     */
    public static function render(string $baseUrl, string $title, string $contentHtml): string
    {
        $logoUrl = self::e(rtrim($baseUrl, '/') . '/img/helloasso-wordmark-noir.png');
        $title = self::e($title);
        $helpUrl = self::e(HelloAssoLinks::HELP_CENTER);
        $legalUrl = self::e(HelloAssoLinks::LEGAL_NOTICE);
        $year = date('Y');

        $noir = self::NOIR;
        $grisFonce = self::GRIS_FONCE;
        $grisClair = self::GRIS_CLAIR;
        $grisTresClair = self::GRIS_TRES_CLAIR;
        $fontTitle = self::FONT_TITLE;
        $fontText = self::FONT_TEXT;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
        </head>
        <body style="margin:0; padding:0; background-color:{$grisTresClair};">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$grisTresClair};">
                <tr>
                    <td align="center" style="padding:32px 16px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#FFFFFF; border:1px solid {$grisClair};">
                            <tr>
                                <td style="padding:32px 40px 0 40px;">
                                    <img src="{$logoUrl}" width="124" height="27" alt="HelloAsso" style="display:block; border:0; width:124px; height:auto;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:48px 40px 40px 40px; font-family:{$fontText}; font-size:16px; line-height:1.3; color:{$grisFonce};">
                                    <h1 style="margin:0 0 32px 0; font-family:{$fontTitle}; font-weight:normal; font-size:38px; line-height:1; text-transform:uppercase; color:{$noir};">{$title}</h1>
                                    {$contentHtml}
                                </td>
                            </tr>
                        </table>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                            <tr>
                                <td style="padding:24px 40px; font-family:{$fontText}; font-size:12px; line-height:1.4; color:{$grisFonce};">
                                    <a href="{$helpUrl}" style="color:{$noir};">Comment fonctionne HelloAsso Stream</a>
                                    &nbsp;·&nbsp;
                                    <a href="{$legalUrl}" style="color:{$noir};">Mentions légales</a>
                                    <br>© {$year} HelloAsso
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }

    /** Paragraphe de texte. Le contenu est du HTML déjà échappé (pour pouvoir y mettre du gras ou un lien). */
    public static function paragraph(string $html): string
    {
        return '<p style="margin:0 0 16px 0;">' . $html . '</p>';
    }

    /** Petit titre de section (Franklin capitales). */
    public static function subtitle(string $text): string
    {
        return '<h2 style="margin:40px 0 16px 0; font-family:' . self::FONT_TITLE . '; font-weight:normal; font-size:26px; line-height:1;'
            . ' text-transform:uppercase; color:' . self::NOIR . ';">' . self::e($text) . '</h2>';
    }

    /** Bouton d'action principal (vert, texte blanc). */
    public static function button(string $label, string $url): string
    {
        $url = self::e($url);
        $label = self::e($label);

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:32px 0;"><tr>'
            . '<td style="background-color:' . self::VERT . '; border-radius:2px;">'
            . '<a href="' . $url . '" style="display:inline-block; padding:16px 24px; font-family:' . self::FONT_TEXT . '; font-size:16px;'
            . ' font-weight:bold; line-height:1.3; color:#FFFFFF; text-decoration:none;">' . $label . ' &rarr;</a>'
            . '</td></tr></table>';
    }

    /** Lien texte souligné, noir. */
    public static function link(string $label, string $url): string
    {
        return '<a href="' . self::e($url) . '" style="color:' . self::NOIR . '; font-weight:bold;">' . self::e($label) . '</a>';
    }

    /** Texte secondaire (adresse de secours, durée de validité…). */
    public static function note(string $html): string
    {
        return '<p style="margin:0 0 16px 0; font-size:14px; color:' . self::GRIS_FONCE . ';">' . $html . '</p>';
    }

    /** Filet de séparation. */
    public static function separator(): string
    {
        return '<hr style="border:none; border-top:1px solid ' . self::GRIS_CLAIR . '; margin:40px 0;">';
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
