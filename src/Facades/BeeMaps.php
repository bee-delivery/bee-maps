<?php

namespace BeeDelivery\BeeMaps\Facades;

use BeeDelivery\BeeMaps\MapServiceFactory;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\Autocomplete autocomplete(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\Geocoding geocoding(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\PlaceSearch placeSearch(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\RouteMatrix routeMatrix(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\RouteOptimization routeOptimization(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 * @method static \BeeDelivery\BeeMaps\Contracts\Services\Routing routing(\BeeDelivery\BeeMaps\Enums\Provider $provider)
 *
 * @see MapServiceFactory
 */
final class BeeMaps extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MapServiceFactory::class;
    }
}
