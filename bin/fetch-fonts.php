<?php

/**
 * Télécharge les polices de la charte HelloAsso dans public/fonts/.
 *
 * Les fichiers sont hébergés sur helloasso.com sans en-tête CORS : un navigateur refuse de les charger
 * depuis un autre domaine. On les sert donc depuis l'app. Ils sont sous licence : on ne les committe pas
 * (repo public), on les récupère à chaque `composer install` (Clever Cloud compris).
 *
 * Un échec n'est pas bloquant : le CSS retombe sur Impact / Arial.
 */

const FONTS_BASE_URL = 'https://www.helloasso.com/_ex/fonts/';
const FONTS = [
    'ITCFranklinGothic-700-v2.woff2',
    'ABCDiatypeRounded-400.woff2',
    'ABCDiatypeRounded-500.woff2',
    'ABCDiatypeRounded-500-italic.woff2',
    'ABCDiatypeRounded-700.woff2',
];

$targetDir = __DIR__ . '/../public/fonts';
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    fwrite(STDERR, "[fonts] Impossible de créer $targetDir\n");
    exit(0);
}

$context = stream_context_create(['http' => ['timeout' => 15]]);

foreach (FONTS as $font) {
    $target = $targetDir . '/' . $font;
    if (is_file($target) && filesize($target) > 0) {
        continue;
    }

    $content = @file_get_contents(FONTS_BASE_URL . $font, false, $context);
    if ($content === false || $content === '') {
        fwrite(STDERR, "[fonts] Échec du téléchargement de $font (repli sur les polices système)\n");
        continue;
    }

    file_put_contents($target, $content);
    echo "[fonts] $font téléchargée\n";
}
