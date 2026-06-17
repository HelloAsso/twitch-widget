<?php

namespace App\Models;

class Goal
{
    public int $id;
    public ?string $charity_stream_guid = null;
    public ?string $charity_event_guid = null;
    public int $amount;
}
