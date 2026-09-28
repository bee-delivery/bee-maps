<?php

namespace BeeDelivery\BeeMaps\DTOs\Requests;

use BeeDelivery\BeeMaps\Enums\TravelMode;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;

final readonly class RouteMatrixRequest
{
    public array $origins;

    public array $destinations;

    /**
     * @param array<int, Coordinates> $origins
     * @param array<int, Coordinates> $destinations
     */
    public function __construct(
        array $origins,
        array $destinations,
        public TravelMode $mode = TravelMode::Drive,
    ) {
        if ($origins === [] || $destinations === []) {
            throw new InvalidRequestException(
                'Uma matriz de rotas precisa de ao menos uma origem e um destino.',
            );
        }

        // Reindexar e obrigatorio: array_filter preserva chaves, e uma lista com
        // buracos vira objeto no json_encode — payload que os dois providers
        // recusam. Alem disso, o originIndex da resposta e posicional: sem
        // reindexar, ele nao casa com as chaves que o chamador enxerga.
        $this->origins = array_values($origins);
        $this->destinations = array_values($destinations);
    }

    /**
     * Numero de pares origem-destino. E esta conta, e nao o numero de pontos,
     * que os dois providers limitam.
     */
    public function elements(): int
    {
        return count($this->origins) * count($this->destinations);
    }
}
