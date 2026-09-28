<?php

namespace BeeDelivery\BeeMaps\DTOs\Requests;

use BeeDelivery\BeeMaps\Enums\OptimizationObjective;
use BeeDelivery\BeeMaps\Enums\TravelMode;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;

final readonly class OptimizeWaypointsRequest
{
    /** @var list<Coordinates> */
    public array $intermediates;

    /**
     * @param ?Coordinates      $destination   Nulo = tour aberto: a rota termina na
     *                                         ultima parada que a otimizacao escolher.
     *                                         Igual a origem = volta ao ponto de partida.
     * @param array<int, Coordinates> $intermediates Paradas a ordenar. A ordem devolvida
     *                                         indexa ESTA lista.
     */
    public function __construct(
        public Coordinates $origin,
        public ?Coordinates $destination = null,
        array $intermediates = [],
        public TravelMode $mode = TravelMode::Drive,
        public OptimizationObjective $objective = OptimizationObjective::MinTravelTime,
    ) {
        if ($intermediates === []) {
            throw new InvalidRequestException(
                'Sem waypoints intermediarios nao ha ordem para devolver.',
            );
        }

        // Mesma razao do RouteMatrixRequest: array_filter preserva chaves, lista
        // com buraco vira objeto no json_encode, e os indices de $order deixam de
        // casar com as chaves que o chamador enxerga.
        $this->intermediates = array_values($intermediates);
    }
}
