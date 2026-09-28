<?php

namespace BeeDelivery\BeeMaps\Tests\Unit\DTOs;

use BeeDelivery\BeeMaps\DTOs\Requests\RouteRequest;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Tests\TestCase;

final class RouteRequestTest extends TestCase
{
    private function point(float $offset): Coordinates
    {
        return new Coordinates(-23.5 - $offset, -46.6 - $offset);
    }

    /**
     * Mesma guarda do OptimizeWaypointsRequest e do RouteMatrixRequest: uma
     * lista com buraco (tipico resultado de array_filter) quebra os DOIS
     * providers por caminhos diferentes — no Google o json_encode emite objeto
     * em vez de lista, no HERE o mapper indexa 0..N-1 direto no array e bate em
     * chave inexistente.
     */
    public function test_intermediates_are_reindexed(): void
    {
        $points = [0 => $this->point(0.1), 2 => $this->point(0.2), 5 => $this->point(0.3)];

        $request = new RouteRequest(
            origin: $this->point(0),
            destination: $this->point(0.9),
            intermediates: $points,
        );

        $this->assertSame([0, 1, 2], array_keys($request->intermediates));
    }

    public function test_reindexing_preserves_the_submitted_order(): void
    {
        $first = $this->point(0.1);
        $second = $this->point(0.2);

        $request = new RouteRequest(
            origin: $this->point(0),
            destination: $this->point(0.9),
            intermediates: [3 => $first, 7 => $second],
        );

        $this->assertSame([$first, $second], $request->intermediates);
    }
}
