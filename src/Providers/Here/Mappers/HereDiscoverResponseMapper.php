<?php

namespace BeeDelivery\BeeMaps\Providers\Here\Mappers;

use BeeDelivery\BeeMaps\DTOs\Responses\Place;
use BeeDelivery\BeeMaps\DTOs\Responses\PlaceCollection;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Providers\Here\HereCoordinates;
use BeeDelivery\BeeMaps\Support\ValueObjects\PlaceReference;

final class HereDiscoverResponseMapper
{
    public function __construct(private readonly HereAddressMapper $addresses = new HereAddressMapper())
    {
    }

    public function toCollection(array $response): PlaceCollection
    {
        $places = [];

        foreach ($response['items'] ?? [] as $item) {
            $places[] = new Place(
                place: isset($item['id']) ? new PlaceReference(Provider::Here, $item['id']) : null,
                name: $item['title'] ?? '',
                address: $this->addresses->fromItem($item['address'] ?? [], $item['title'] ?? ''),
                coordinates: HereCoordinates::fromItem($item),
            );
        }

        return new PlaceCollection(...$places);
    }
}
