<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Tests\TestCase;

class ChannelRegistrationTest extends TestCase
{
    public function test_channel_is_listed()
    {
        $channels = \Eventy::filter('channels.list', []);

        $this->assertArrayHasKey(106, $channels);
        $this->assertSame('Website chat', $channels[106]);
    }

    public function test_channel_name_filter_maps_code()
    {
        $this->assertSame('Website chat', \Eventy::filter('channel.name', 'x', 106));
        $this->assertSame('x', \Eventy::filter('channel.name', 'x', 107));
    }
}
