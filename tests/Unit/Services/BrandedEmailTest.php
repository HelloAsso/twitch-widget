<?php

namespace Tests\Unit\Services;

use App\Services\BrandedEmail;
use App\Services\HelloAssoLinks;
use PHPUnit\Framework\TestCase;

class BrandedEmailTest extends TestCase
{
    public function testRenderUsesHelloAssoWordmarkFromAppDomain(): void
    {
        $html = BrandedEmail::render('https://stream.test/', 'Titre', '');

        $this->assertStringContainsString('src="https://stream.test/img/helloasso-wordmark-noir.png"', $html);
        $this->assertStringContainsString('alt="HelloAsso"', $html);
    }

    public function testRenderLinksToHelpCenterAndLegalNotice(): void
    {
        $html = BrandedEmail::render('https://stream.test', 'Titre', '');

        $this->assertStringContainsString(BrandedEmail::e(HelloAssoLinks::HELP_CENTER), $html);
        $this->assertStringContainsString(HelloAssoLinks::LEGAL_NOTICE, $html);
    }

    public function testRenderEscapesTitle(): void
    {
        $html = BrandedEmail::render('https://stream.test', '<script>alert(1)</script>', '');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testButtonUsesCharteGreenAndEscapesUrl(): void
    {
        $html = BrandedEmail::button('Activer mon compte', 'https://stream.test/verify-email/abc?x="1"');

        $this->assertStringContainsString('background-color:#4A8100', $html);
        $this->assertStringContainsString('Activer mon compte', $html);
        $this->assertStringContainsString('href="https://stream.test/verify-email/abc?x=&quot;1&quot;"', $html);
    }
}
