<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Services\SystemChecks;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Final review I1: the per-IP limits only work when FreeScout sees each
 * visitor's own address. System -> Status shows the address FreeScout sees
 * for the admin's request and warns when it can't be a visitor's.
 */
class ClientAddressCheckTest extends TestCase
{
    public function test_a_public_address_is_ok()
    {
        foreach (['8.8.8.8', '2a00:1450:4001:82a::200e', '::ffff:8.8.8.8'] as $ip) {
            $row = SystemChecks::clientAddressRow($ip, null, $ip);
            $this->assertSame('Client address', $row['label']);
            $this->assertTrue($row['ok'], $ip);
            $this->assertStringContainsString($ip, $row['detail']);
        }
    }

    public function test_private_loopback_and_link_local_addresses_warn()
    {
        $local = [
            '10.0.2.100',       // rootless podman (slirp4netns)
            '172.17.0.1',       // a Docker bridge
            '192.168.1.5',
            '100.92.247.48',    // shared address space (CGNAT, Tailscale)
            '127.0.0.1',
            '169.254.10.20',
            '0.0.0.0',
            '::1',
            '::',
            'fe80::1',
            'fd12:3456:789a::1', // unique local
            'fc00::5',
            '::ffff:10.0.0.1',
            '::ffff:127.0.0.1',
            '::ffff:192.168.0.1',
            'fe80::1%eth0',     // with a zone id: not a plain address
            '',
            'garbage',
        ];

        foreach ($local as $ip) {
            $row = SystemChecks::clientAddressRow($ip, null, $ip);
            $this->assertFalse($row['ok'], $ip === '' ? '(empty)' : $ip);
            $this->assertStringContainsString('APP_TRUSTED_PROXIES', $row['detail'], $ip);
        }
    }

    public function test_an_ignored_forwarded_header_warns_even_for_a_public_proxy()
    {
        // A CDN with public addresses that FreeScout doesn't trust: every
        // visitor would share the CDN's addresses.
        $row = SystemChecks::clientAddressRow('8.8.4.4', '203.0.113.9', '8.8.4.4');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('X-Forwarded-For', $row['detail']);
        $this->assertStringContainsString('APP_TRUSTED_PROXIES', $row['detail']);
        $this->assertStringContainsString('8.8.4.4', $row['detail']);
    }

    public function test_a_trusted_proxy_passes_the_visitor_address_on()
    {
        // The proxy (10.0.0.5) is trusted: ip() is the forwarded address.
        $row = SystemChecks::clientAddressRow('8.8.8.8', '8.8.8.8', '10.0.0.5');
        $this->assertTrue($row['ok']);

        // Trusted, but the admin is on the LAN: still a private address.
        $row = SystemChecks::clientAddressRow('192.168.1.5', '192.168.1.5', '10.0.0.5');
        $this->assertFalse($row['ok']);
    }

    public function test_an_empty_forwarded_header_counts_as_none()
    {
        $this->assertTrue(SystemChecks::clientAddressRow('8.8.8.8', '', '8.8.8.8')['ok']);
        $this->assertTrue(SystemChecks::clientAddressRow('8.8.8.8', '  ', '8.8.8.8')['ok']);
    }

    public function test_system_status_shows_the_row_for_the_admins_request()
    {
        $labels = array_column(SystemChecks::rows(), 'label');
        $this->assertContains('Client address', $labels);

        // No trusted proxy, whatever this install's .env says.
        config(['trustedproxy.proxies' => []]);
        $html = $this->actingAs($this->adminUser())
            ->call('GET', '/system/status', [], [], [], ['REMOTE_ADDR' => '10.0.2.100', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8'])
            ->assertStatus(200)
            ->getContent();
        $this->assertStringContainsString('Client address', $html);
        // FreeScout ignores the header of an untrusted proxy.
        $this->assertStringContainsString('10.0.2.100', $html);
        $this->assertStringContainsString('X-Forwarded-For', $html);
    }
}
