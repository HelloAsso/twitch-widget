<?php

namespace App\Models;

class Event implements HasRight
{
    public $id;
    public $guid;
    public $title;
    public $creation_date;
    public $last_update;
    public $admin;
    public $is_test_mode = 0;
    public $test_amount = 0;

    public function getRightColumn(): string { return 'id_charity_event'; }
    public function getRightId(): int { return (int) $this->id; }
}
