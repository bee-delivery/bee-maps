<?php

namespace BeeDelivery\BeeMaps\Providers\Here\Mappers;

use BeeDelivery\BeeMaps\DTOs\Requests\PlaceSearchRequest;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\CountryCode;

final class HereDiscoverRequestMapper
{
    /**
     * O Discover exige contexto geografico — `at` ou `in` — enquanto o
     * places:searchText do Google nao exige nada. Quando a requisicao nao traz
     * coordenada, caimos na regiao (da requisicao ou do config); sem nenhuma
     * das duas, e melhor falhar dizendo o que faltou do que deixar o HERE
     * responder 400 com uma mensagem que nao menciona o contrato do pacote.
     */
    public function toQuery(PlaceSearchRequest $request, string $language, string $region): array
    {
        $query = [
            'q' => $request->query,
            'lang' => $language,
        ];

        if ($request->near !== null) {
            $query['at'] = $request->near->toString();

            return $query;
        }

        $country = $request->region ?? $region;

        if ($country === '') {
            throw new InvalidRequestException(
                'O Discover do HERE exige contexto geografico: informe PlaceSearchRequest::$near, '
                . 'PlaceSearchRequest::$region, ou configure bee-maps.defaults.region.',
            );
        }

        $query['in'] = 'countryCode:' . CountryCode::toAlpha3($country);

        return $query;
    }
}
