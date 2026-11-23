<?php

namespace App\Models;

class WidgetCard
{
    public ?int $id = null;
    public ?string $charity_stream_guid = null;
    public ?string $charity_event_guid = null;
    public ?string $image = null;
    public string $tag = '';
    public string $title = '';
    public string $description = '';
    public string $background_color = '#ffffff';
    public string $bar_color = '#4a8100';
    public string $bar_background_color = '#cdced0';
    public string $text_color = '#101010';
    public string $tag_color = '#572a37';
    public string $tag_background_color = '#f1edff';
    public int $start_amount = 0;
    public int $start_donors = 0;
    public ?string $cache_data = null;
    public ?string $cache_updated_at = null;
    public ?string $creation_date = null;
    public ?string $last_update = null;
}
