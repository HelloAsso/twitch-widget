<?php

namespace App\Models;

class Event
{
    public $id;
    public $guid;
    public $title;
    public $goal;
    public $creation_date;
    public $last_update;
    public $admin;
    public $is_test_mode = 0;
    public $test_amount = 0;
}
