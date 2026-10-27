<?php

namespace BeeDelivery\BeeMaps\Tests\Unit\Providers\Here;

use BeeDelivery\BeeMaps\Providers\Here\HereCoordinates;
use BeeDelivery\BeeMaps\Tests\TestCase;

final class HereCoordinatesTest extends TestCase
{
    public function test_the_access_point_wins_over_the_position(): void
    {
        $coordinates = HereCoordinates::fromItem([
            'position' => ['lat' => -5.18618, 'lng' => -37.34591],
            'access' => [['lat' => -5.18675, 'lng' => -37.34641], ['lat' => -5.18600, 'lng' => -37.34500]],
        ]);

        // Com mais de um acesso, vale o primeiro.
        $this->assertSame(-5.18675, $coordinates->latitude);
        $this->assertSame(-37.34641, $coordinates->longitude);
    }

    public function test_without_access_it_falls_back_to_the_position(): void
    {
        $coordinates = HereCoordinates::fromItem(['position' => ['lat' => -5.18618, 'lng' => -37.34591]]);

        $this->assertSame(-5.18618, $coordinates->latitude);
        $this->assertSame(-37.34591, $coordinates->longitude);
    }

    public function test_an_empty_or_incomplete_access_falls_back_to_the_position(): void
    {
        $position = ['lat' => -5.18618, 'lng' => -37.34591];

        $this->assertSame(-5.18618, HereCoordinates::fromItem(['position' => $position, 'access' => []])->latitude);
        $this->assertSame(-5.18618, HereCoordinates::fromItem(['position' => $position, 'access' => [['lat' => -5.1]]])->latitude);
    }

    public function test_without_access_and_position_there_is_no_coordinate(): void
    {
        $this->assertNull(HereCoordinates::fromItem(['title' => 'Postos Shell', 'resultType' => 'chainQuery']));
    }
}
