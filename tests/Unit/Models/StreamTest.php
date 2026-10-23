<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Stream;
use PHPUnit\Framework\TestCase;

class StreamTest extends TestCase
{
    public function testGetStartDateTimeIsNullByDefault(): void
    {
        $this->assertNull((new Stream())->getStartDateTime());
    }

    public function testGetStartDateTimeIsNullWhenEmpty(): void
    {
        $stream = new Stream();
        $stream->start_date = '';

        $this->assertNull($stream->getStartDateTime());
    }

    public function testGetStartDateTimeIsInterpretedInParisTime(): void
    {
        $stream = new Stream();
        $stream->start_date = '2026-10-21 20:00:00';

        $date = $stream->getStartDateTime();

        $this->assertSame('Europe/Paris', $date->getTimezone()->getName());
        $this->assertSame('2026-10-21T18:00:00+00:00', $date->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }
}
