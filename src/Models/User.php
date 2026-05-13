<?php

namespace App\Models;

class User
{
    public $id;
    public $email;
    public $password;
    public $email_verified;
    public $role;
    public $reset_token;
    public $reset_token_expires_at;
    public $creation_date;
    public $last_update;
}
