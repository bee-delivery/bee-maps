<?php

namespace BeeDelivery\BeeMaps\Tests\Feature;

use BeeDelivery\BeeMaps\DTOs\Requests\AutocompleteRequest;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Exceptions\ConfigurationException;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\MapServiceFactory;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class HereAutocompleteTest extends TestCase
{
    private function fakeAutosuggest(): void
    {
        Http::fake([
            'autosuggest.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/autosuggest.json'), true),
                200,
            ),
        ]);
    }

    private function fakeAutocomplete(): void
    {
        Http::fake([
            'autocomplete.search.hereapi.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/here/autocomplete.json'), true),
                200,
            ),
        ]);
    }

    private function strategy(string $value): void
    {
        $this->app['config']->set('bee-maps.here.autocomplete_strategy', $value);
    }

    private function suggest(AutocompleteRequest $request)
    {
        return $this->app->make(MapServiceFactory::class)
            ->autocomplete(Provider::Here)
            ->suggest($request);
    }

    public function test_with_coordinates_the_default_goes_to_autosuggest(): void
    {
        // Sem fake do /autocomplete: se o despacho errar o endpoint,
        // preventStrayRequests quebra o teste em vez de mascarar a rota.
        $this->fakeAutosuggest();

        $collection = $this->suggest(new AutocompleteRequest('Av Paulista', new Coordinates(-23.5, -46.6)));

        $this->assertCount(4, $collection);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'apiKey=chave-here-de-teste'));
    }

    public function test_without_coordinates_the_default_goes_to_autocomplete_and_covers_the_country(): void
    {
        $this->fakeAutocomplete();

        $collection = $this->suggest(new AutocompleteRequest('Rua Blumenau'));

        $this->assertCount(3, $collection);

        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            // O caso "cadastro de empresa": sem ponto de referencia, a busca
            // tem que valer para o Brasil inteiro. O /autosuggest responderia
            // 400 aqui, porque nele `in=countryCode` precisa vir acompanhado
            // de `at`/`in=circle`/`in=bbox`.
            $this->assertStringContainsString('in=countryCode:BRA', $url);
            $this->assertStringNotContainsString('at=', $url);
            $this->assertStringNotContainsString('circle:', $url);

            return true;
        });
    }

    public function test_autosuggest_carries_the_position_so_the_consumer_skips_the_geocode(): void
    {
        $this->fakeAutosuggest();

        $collection = $this->suggest(new AutocompleteRequest('Av Paulista', new Coordinates(-23.5, -46.6)));

        $first = $collection->first();

        $this->assertNotNull($first->coordinates);
        $this->assertEqualsWithDelta(-23.5615, $first->coordinates->latitude, 0.0001);
        $this->assertEqualsWithDelta(-46.6562, $first->coordinates->longitude, 0.0001);

        // O item de lugar tambem traz posicao: nao e privilegio de endereco.
        // E vem do `access` (a chegada pela rua), nao do `position`.
        $place = $collection->all()[1];
        $this->assertTrue($place->isEstablishment);
        $this->assertNotNull($place->coordinates);
        $this->assertSame(-23.57048, $place->coordinates->latitude);
        $this->assertSame(-46.64157, $place->coordinates->longitude);
    }

    public function test_a_search_refinement_has_no_position_just_like_it_has_no_place(): void
    {
        $this->fakeAutosuggest();

        $collection = $this->suggest(new AutocompleteRequest('Postos', new Coordinates(-23.5, -46.6)));

        // O chainQuery da fixture: um refinamento de busca, sem `position` e
        // sem `id`. Os dois campos caem juntos, e e isso que o consumidor usa
        // para saber que nao ha nada para resolver.
        $refinement = $collection->all()[3];

        $this->assertNull($refinement->place);
        $this->assertNull($refinement->coordinates);
    }

    public function test_autocomplete_has_no_position_and_the_consumer_still_needs_the_lookup(): void
    {
        $this->fakeAutocomplete();

        $collection = $this->suggest(new AutocompleteRequest('Rua Blumenau'));

        foreach ($collection as $suggestion) {
            // O endpoint nao devolve posicao e nao ha `show` que adicione uma:
            // aqui o caminho continua sendo lookup($place).
            $this->assertNull($suggestion->coordinates);
            $this->assertNotNull($suggestion->place);
        }
    }

    public function test_a_configured_center_does_not_shrink_the_nationwide_search_in_auto_mode(): void
    {
        // O autosuggest_center segue setado pelo defineEnvironment. Se ele
        // influenciasse o modo `auto`, esta chamada viraria um circle de 50 km
        // em volta de Sao Paulo e "Rua Blumenau" (Joinville) sumiria.
        $this->assertNotNull($this->app['config']->get('bee-maps.here.autosuggest_center'));

        $this->fakeAutocomplete();

        $this->suggest(new AutocompleteRequest('Rua Blumenau'));

        Http::assertSent(fn ($request) => ! str_contains(urldecode($request->url()), 'circle:'));
    }

    public function test_near_and_radius_produce_circle_and_countryCode_as_repeated_in_parameters(): void
    {
        $this->fakeAutosuggest();

        $this->suggest(new AutocompleteRequest('Av Paulista', new Coordinates(-23.5, -46.6), 3000, ['BR']));

        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            $this->assertStringNotContainsString('in[0]=', $url);
            $this->assertStringContainsString('in=circle:-23.5000000,-46.6000000;r=3000', $url);
            $this->assertStringContainsString('in=countryCode:BRA', $url);
            // 400 "Mutually exclusive parameters violated" se `at` vier junto.
            $this->assertStringNotContainsString('at=', $url);

            return true;
        });
    }

    public function test_the_forced_autocomplete_strategy_uses_the_endpoint_even_with_coordinates(): void
    {
        $this->strategy('autocomplete');
        $this->fakeAutocomplete();

        $this->suggest(new AutocompleteRequest('Av Paulista', new Coordinates(-23.5, -46.6), 3000, ['BR']));

        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            $this->assertStringContainsString('autocomplete.search.hereapi.com', $url);
            $this->assertStringContainsString('in=circle:-23.5000000,-46.6000000;r=3000', $url);

            return true;
        });
    }

    public function test_the_forced_autosuggest_strategy_falls_back_to_the_configured_center_without_coordinates(): void
    {
        $this->strategy('autosuggest');
        $this->fakeAutosuggest();

        $this->suggest(new AutocompleteRequest('Av Paulista'));

        Http::assertSent(
            fn ($request) => str_contains(urldecode($request->url()), 'circle:-23.5615000,-46.6562000;r=50000'),
        );
    }

    public function test_the_autosuggest_strategy_without_center_or_coordinates_fails_before_the_network(): void
    {
        // Sem Http::fake: se a excecao nao vier, preventStrayRequests quebra o
        // teste — que e o comportamento desejado.
        $this->strategy('autosuggest');
        $this->app['config']->set('bee-maps.here.autosuggest_center', null);

        $this->expectException(InvalidRequestException::class);

        $this->suggest(new AutocompleteRequest('Av Paulista'));
    }

    public function test_an_invalid_strategy_is_a_configuration_error(): void
    {
        $this->strategy('discover');

        $this->expectException(ConfigurationException::class);

        $this->suggest(new AutocompleteRequest('Av Paulista'));
    }

    public function test_empty_countries_uses_the_configured_region_as_the_default(): void
    {
        $this->fakeAutocomplete();

        $this->suggest(new AutocompleteRequest('Av Paulista'));

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'in=countryCode:BRA'));
    }
}
