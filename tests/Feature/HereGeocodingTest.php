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
}
