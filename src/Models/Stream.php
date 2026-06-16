<?php

namespace App\Models;

class Stream
{
    public $id;
    public $charity_event_id;
    public $guid;
    public $title;
    public $goal;
    public $form_slug;
    public $form_type = 'Donation';
    public $organization_slug;
    public $creation_date;
    public $last_update;
    public $admin;
    public $is_test_mode = 0;
    public $test_amount = 0;
}
