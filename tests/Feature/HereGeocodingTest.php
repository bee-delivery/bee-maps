<?php

namespace BeeDelivery\BeeMaps\Tests\Feature;

use BeeDelivery\BeeMaps\DTOs\Requests\GeocodeFilters;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Exceptions\PlaceReferenceProviderMismatchException;
use BeeDelivery\BeeMaps\MapServiceFactory;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Support\ValueObjects\PlaceReference;
use BeeDelivery\BeeMaps\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class HereGeocodingTest extends TestCase
{
    private function geocoding()
    {
        return $this->app->make(MapServiceFactory::class)->geocoding(Provider::Here);
    }

    public function test_geocode_calls_the_geocode_host(): void
    {
        Http::fake([
            'geocode.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/geocode.json'), true),
                200,
            ),
        ]);

        $collection = $this->geocoding()->geocode('Av Paulista 1000');

        $this->assertCount(1, $collection);
        // Sem `access` no item, a coordenada e o `position`.
        $this->assertSame(-23.5615, $collection->first()->coordinates->latitude);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'q=Av Paulista 1000'));
    }

    public function test_reverse_calls_the_revgeocode_host(): void
    {
        Http::fake([
            'revgeocode.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/geocode.json'), true),
                200,
            ),
        ]);

        $this->geocoding()->reverse(new Coordinates(-23.5615, -46.6562));

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'at=-23.5615000,-46.6562000'));
    }

    public function test_lookup_calls_the_lookup_host_and_returns_a_result(): void
    {
        Http::fake([
            'lookup.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/lookup.json'), true),
                200,
            ),
        ]);

        $result = $this->geocoding()->lookup(new PlaceReference(Provider::Here, 'here:pds:place:076sxxxx-abcdef'));

        $this->assertNotNull($result);
        // O lugar traz `access`: a coordenada e a da chegada pela rua.
        $this->assertSame(-23.57048, $result->coordinates->latitude);
        $this->assertSame(-46.64157, $result->coordinates->longitude);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'id=here:pds:place:076sxxxx-abcdef'));
    }

    public function test_lookup_refuses_a_google_place_id(): void
    {
        $this->expectException(PlaceReferenceProviderMismatchException::class);

        $this->geocoding()->lookup(new PlaceReference(Provider::Google, 'ChIJ0WGkg4FEzpQRrlsz_whLqZs'));
    }

    public function test_an_alpha2_country_filter_arrives_as_alpha3_countryCode(): void
    {
        Http::fake([
            'geocode.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/geocode.json'), true),
                200,
            ),
        ]);

        $this->geocoding()->geocode('Av Paulista 1000', new GeocodeFilters(country: 'BR'));

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'in=countryCode:BRA'));
    }

    public function test_an_alpha3_country_filter_also_works(): void
    {
        Http::fake([
            'geocode.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/geocode.json'), true),
                200,
            ),
        ]);

        $this->geocoding()->geocode('Av Paulista 1000', new GeocodeFilters(country: 'BRA'));

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'in=countryCode:BRA'));
    }

    public function test_an_invalid_country_filter_throws(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->geocoding()->geocode('Av Paulista 1000', new GeocodeFilters(country: 'XX'));
    }

    private function fakeGeocode(): void
    {
        Http::fake([
            'geocode.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/geocode.json'), true),
                200,
            ),
        ]);
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_postal_code_filter_goes_to_qq_and_not_to_q(): void
    {
        $this->fakeGeocode();

        $this->geocoding()->geocode(
            'Rua Barão de Jaguara, 100, Centro, Campinas, SP, 13015001',
            new GeocodeFilters(postalCode: '13015001', country: 'BR'),
        );

        Http::assertSent(function ($request) {
            $query = $this->queryOf($request->url());

            return $query['q'] === 'Rua Barão de Jaguara, 100, Centro, Campinas, SP, 13015001'
                && $query['qq'] === 'postalCode=13015001'
                && $query['in'] === 'countryCode:BRA';
        });
    }

    public function test_city_filter_goes_to_qq(): void
    {
        $this->fakeGeocode();

        $this->geocoding()->geocode('Rua Barão de Jaguara, 100', new GeocodeFilters(city: 'Campinas', country: 'BR'));

        Http::assertSent(fn ($request) => $this->queryOf($request->url())['qq'] === 'city=Campinas'
            && $this->queryOf($request->url())['q'] === 'Rua Barão de Jaguara, 100');
    }

    public function test_city_and_postal_code_share_the_qq(): void
    {
        $this->fakeGeocode();

        $this->geocoding()->geocode('Rua Barão de Jaguara, 100', new GeocodeFilters(city: 'Campinas', postalCode: '13015001'));

        Http::assertSent(fn ($request) => $this->queryOf($request->url())['qq'] === 'city=Campinas;postalCode=13015001');
    }

    public function test_without_city_or_postal_code_there_is_no_qq(): void
    {
        $this->fakeGeocode();

        $this->geocoding()->geocode('Av Paulista 1000', new GeocodeFilters(country: 'BR'));

        Http::assertSent(fn ($request) => !array_key_exists('qq', $this->queryOf($request->url()))
            && $this->queryOf($request->url())['q'] === 'Av Paulista 1000');
    }

    public function test_qq_separators_inside_a_value_are_neutralized(): void
    {
        $this->fakeGeocode();

        $this->geocoding()->geocode('Rua X, 1', new GeocodeFilters(city: 'São Paulo;postalCode=1'));

        Http::assertSent(fn ($request) => $this->queryOf($request->url())['qq'] === 'city=São Paulo postalCode 1');
    }
}
