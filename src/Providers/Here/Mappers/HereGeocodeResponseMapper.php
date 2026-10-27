<?php

namespace BeeDelivery\BeeMaps\Providers\Here\Mappers;

use BeeDelivery\BeeMaps\DTOs\Responses\GeocodeResult;
use BeeDelivery\BeeMaps\DTOs\Responses\GeocodeResultCollection;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Providers\Here\HereCoordinates;
use BeeDelivery\BeeMaps\Support\ValueObjects\PlaceReference;

final class HereGeocodeResponseMapper
{
    /**
     * @param float $partialThreshold Abaixo deste queryScore (0 a 1) o resultado e considerado
     *                                parcial. O default de 1.0 e deliberadamente conservador e
     *                                nao foi calibrado contra dados reais: consumidores cuja
     *                                logica de negocio ramifica em cima de `partial` devem
     *                                calibrar este valor contra o proprio corpus de enderecos
     *                                antes de trocar de provider.
     */
    public function __construct(
        private readonly float $partialThreshold = 1.0,
        private readonly HereAddressMapper $addresses = new HereAddressMapper(),
    ) {
    }

    /**
     * Aceita tanto {"items": [...]} (geocode e revgeocode) quanto um objeto unico (lookup).
     */
    public function toCollection(array $response): GeocodeResultCollection
    {
        $items = array_key_exists('items', $response)
            ? $response['items']
            : (isset($response['position']) ? [$response] : []);

        return new GeocodeResultCollection(...array_map(
            fn (array $item) => $this->toResult($item),
            $items,
        ));
    }

    private function toResult(array $item): GeocodeResult
    {
        $score = isset($item['scoring']['queryScore']) ? (float) $item['scoring']['queryScore'] : null;

        return new GeocodeResult(
            address: $this->addresses->fromItem($item['address'] ?? [], $item['title'] ?? ''),
            coordinates: HereCoordinates::fromItem($item),
            // O HERE nao tem equivalente ao partial_match: derivamos do queryScore.
            partial: $score !== null && $score < $this->partialThreshold,
            matchScore: $score,
            place: isset($item['id']) ? new PlaceReference(Provider::Here, $item['id']) : null,
        );
    }
}
