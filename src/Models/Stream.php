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

    public function getRightColumn(): string { return 'id_charity_stream'; }
    public function getRightId(): int { return (int) $this->id; }
}
