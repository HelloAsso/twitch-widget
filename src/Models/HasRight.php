<?php

namespace App\Models;

interface HasRight
{
    public function getRightColumn(): string;
    public function getRightId(): int;
}
