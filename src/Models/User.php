<?php

namespace App\Models;

class User
{
    public $id;
    public $email;
    public $password;
    public $role;
    public $reset_token;
    public $creation_date;
    public $last_update;
}
