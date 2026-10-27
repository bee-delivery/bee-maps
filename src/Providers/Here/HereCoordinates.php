<?php

namespace BeeDelivery\BeeMaps\Providers\Here;

use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;

/**
 * Coordenada de um item do Geocoding & Search: o primeiro `access` quando
 * existe, o `position` quando nao.
 *
 * O `position` de um lugar e o ponto do estabelecimento (o meio do terreno,
 * o predio); o `access` e o ponto de chegada pela via, que e onde o entregador
 * para. Foi a orientacao da consultoria do HERE para quem precisa do ponto de
 * chegada. O `access` nao vem sempre, por isso o fallback.
 *
 * geocode, revgeocode, lookup, discover e autosuggest decidem igual por aqui:
 * o autosuggest e o lookup do mesmo lugar tem que devolver o mesmo ponto.
 */
final class HereCoordinates
{
    public static function fromItem(array $item): ?Coordinates
    {
        foreach ([$item['access'][0] ?? null, $item['position'] ?? null] as $point) {
            if (isset($point['lat'], $point['lng'])) {
                return new Coordinates((float) $point['lat'], (float) $point['lng']);
            }
        }

        return null;
    }
}
