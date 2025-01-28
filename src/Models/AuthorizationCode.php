<?php

namespace App\Models;

class AuthorizationCode
{

    public $id;
    public $code_verifier;
    public $organization_slug;
    public $redirect_uri;
    public $creation_date;
    public $last_update;
}
