<?php

namespace App\Models;

class Stream implements HasRight
{
    public $id;
    public $charity_event_id;
    public $guid;
    public $title;
    public $form_slug;
    public $form_type = 'Donation';
    public $organization_slug;
    public $creation_date;
    public $last_update;
    public $admin;
    public $is_test_mode = 0;
    public $test_amount = 0;
    public $live_started_at;
    public $live_continuation_token;
    public $live_cache_updated_at;
    /** Date de début de prise en compte des paiements (heure de Paris), null = tous les paiements. */
    public $start_date = null;

    public const TIMEZONE = 'Europe/Paris';

    /**
     * Nettoie un slug d'association ou de formulaire saisi à la main.
     * Copié depuis la barre d'adresse, il peut garder des paramètres d'URL (ex. `yg?_gl=1*3vyno6*...` ajouté
     * par Google Analytics) qui font échouer tous les appels à l'API HelloAsso.
     */
    public static function normalizeSlug(string $slug): string
    {
        $slug = preg_split('/[?#]/', $slug, 2)[0];

        return trim($slug, " \t\n\r\0\x0B/");
    }

    public function getStartDateTime(): ?\DateTimeImmutable
    {
        if (empty($this->start_date)) {
            return null;
        }

        return new \DateTimeImmutable($this->start_date, new \DateTimeZone(self::TIMEZONE));
    }

    public function getRightColumn(): string { return 'id_charity_stream'; }
    public function getRightId(): int { return (int) $this->id; }
}
