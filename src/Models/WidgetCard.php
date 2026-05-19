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
    public int $goal = 1000;
    public string $background_color = '#ffffff';
    public string $bar_color = '#2563eb';
    public string $bar_background_color = '#e5e7eb';
    public string $text_color = '#1a1a1a';
    public string $tag_color = '#166534';
    public string $tag_background_color = '#dcfce7';
    public ?string $cache_data = null;
    public ?string $cache_updated_at = null;
    public ?string $creation_date = null;
    public ?string $last_update = null;
}
