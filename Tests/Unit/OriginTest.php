<?php

namespace Modules\WordPressChat\Tests\Unit;

use Modules\WordPressChat\Services\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function test_normalizes_urls()
    {
        $this->assertSame('https://shop.example.com', Origin::normalize('https://Shop.Example.com/path?q=1'));
        $this->assertSame('https://x.test', Origin::normalize('https://x.test:443'));
        $this->assertSame('http://x.test', Origin::normalize('http://x.test:80/a#b'));
        $this->assertSame('http://x.test:8080', Origin::normalize('http://x.test:8080/'));
        $this->assertSame('https://x.test:80', Origin::normalize('HTTPS://x.test:80'));
    }

    public function test_rejects_non_http_urls()
    {
        $this->assertNull(Origin::normalize('ftp://x'));
        $this->assertNull(Origin::normalize('example.com'));
        $this->assertNull(Origin::normalize(''));
        $this->assertNull(Origin::normalize('https://'));
    }

    public function test_idn_host_is_punycoded()
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        $this->assertSame('https://xn--bcher-kva.example', Origin::normalize('https://bücher.example'));
        $this->assertSame('https://xn--bcher-kva.example:8443', Origin::normalize('https://BÜCHER.example:8443/x'));
        $this->assertSame('https://xn--bcher-kva.example', Origin::normalize('https://xn--bcher-kva.example'));
    }

    public function test_idn_without_intl_returns_null()
    {
        if (function_exists('idn_to_ascii')) {
            $this->markTestSkipped('The intl extension is available.');
        }

        $this->assertNull(Origin::normalize('https://bücher.example'));
    }
}
