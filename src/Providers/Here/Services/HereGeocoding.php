<?php

namespace BeeDelivery\BeeMaps\Providers\Here\Services;

use BeeDelivery\BeeMaps\Contracts\Services\Geocoding;
use BeeDelivery\BeeMaps\DTOs\Requests\GeocodeFilters;
use BeeDelivery\BeeMaps\DTOs\Responses\GeocodeResult;
use BeeDelivery\BeeMaps\DTOs\Responses\GeocodeResultCollection;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Enums\Service;
use BeeDelivery\BeeMaps\Providers\Here\Mappers\HereGeocodeResponseMapper;
use BeeDelivery\BeeMaps\Support\CountryCode;
use BeeDelivery\BeeMaps\Support\Http\MapsHttpClient;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Support\ValueObjects\PlaceReference;

final class HereGeocoding implements Geocoding
{
    public function __construct(
        private readonly MapsHttpClient $http,
        private readonly HereGeocodeResponseMapper $mapper,
        private readonly array $endpoints,
        private readonly string $apiKey,
        private readonly string $language,
    ) {
    }

    public function geocode(string $query, ?GeocodeFilters $filters = null): GeocodeResultCollection
    {
        $parameters = [];

        if (trim($query) !== '') {
            $parameters['q'] = $query;
        }

        $qualified = $this->qualifiedQuery($filters);
        if ($qualified !== null) {
            $parameters['qq'] = $qualified;
        }

        if ($filters?->country !== null) {
            $parameters['in'] = 'countryCode:' . CountryCode::toAlpha3($filters->country);
        }

        return $this->fetch($this->endpoints['geocode'], $parameters);
    }

    public function reverse(Coordinates $coordinates): GeocodeResultCollection
    {
        return $this->fetch($this->endpoints['revgeocode'], ['at' => $coordinates->toString()]);
    }

    public function lookup(PlaceReference $place): ?GeocodeResult
    {
        $place->assertBelongsTo(Provider::Here);

        return $this->fetch($this->endpoints['lookup'], ['id' => $place->id])->first();
    }

    private function fetch(string $url, array $parameters): GeocodeResultCollection
    {
        $response = $this->http->get(
            Provider::Here,
            Service::Geocoding,
            $url,
            $parameters + ['lang' => $this->language, 'apiKey' => $this->apiKey],
        );

        return $this->mapper->toCollection($response);
    }

    /**
     * Cidade e CEP vao no `qq` (qualified query), que o HERE combina com o
     * texto livre do `q` ("hybrid query"). Antes iam colados no fim do `q`, e
     * como o endereco quase sempre ja termina com o CEP, a restricao nao
     * chegava ao HERE.
     *
     * Nao e filtro rigido como o `components=postal_code:` do Google: o HERE
     * pode devolver item de outro CEP ou de outra cidade. Quem precisa da
     * garantia confere `address->postalCode`/`address->city` do resultado.
     */
    private function qualifiedQuery(?GeocodeFilters $filters): ?string
    {
        $fields = array_filter(
            ['city' => $filters?->city, 'postalCode' => $filters?->postalCode],
            fn (?string $value) => $value !== null && trim($value) !== '',
        );

        if ($fields === []) {
            return null;
        }

        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name . '=' . $this->qualifiedValue($value);
        }

        return implode(';', $parts);
    }

    /** `;` separa os campos e `=` separa nome e valor: dentro do valor viram espaco. */
    private function qualifiedValue(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace([';', '='], ' ', $value)));
    }
}
