<?php

namespace App\Models;

class AccessToken
{
    public $id;
    public $access_token;
    public $refresh_token;
    public $organization_slug;
    public $access_token_expires_at;
    public $refresh_token_expires_at;
    public $creation_date;
    public $last_update;
}
