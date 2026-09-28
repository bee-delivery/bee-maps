<?php

namespace BeeDelivery\BeeMaps\Tests\Live;

use BeeDelivery\BeeMaps\DTOs\Requests\AutocompleteRequest;
use BeeDelivery\BeeMaps\DTOs\Requests\OptimizeWaypointsRequest;
use BeeDelivery\BeeMaps\DTOs\Requests\PlaceSearchRequest;
use BeeDelivery\BeeMaps\DTOs\Requests\RouteMatrixRequest;
use BeeDelivery\BeeMaps\DTOs\Requests\RouteRequest;
use BeeDelivery\BeeMaps\Enums\OptimizationObjective;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Enums\TravelMode;
use BeeDelivery\BeeMaps\MapServiceFactory;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bate na API real. Roda a mao:
 *
 *   GOOGLE_MAPS_KEY=... HERE_API_KEY=... vendor/bin/phpunit --group live
 *
 * Nao asserta valores — asserta que a chamada FUNCIONA: endpoint existe, chave
 * tem acesso, contrato volta preenchido. E a unica camada que pega credencial
 * sem escopo, endpoint renomeado e SKU nao habilitado.
 */
#[Group('live')]
final class SmokeLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A TestCase base bloqueia requisicao real; aqui e o oposto do resto
        // da suite — o ponto e justamente sair para a rede.
        Http::allowStrayRequests();
    }

    private static function key(Provider $provider): ?string
    {
        return getenv($provider === Provider::Google ? 'GOOGLE_MAPS_KEY' : 'HERE_API_KEY') ?: null;
    }

    /**
     * O gate e por provedor, nao por suite: ter so uma das duas chaves e comum
     * — investigar um comportamento do HERE, por exemplo — e nao deveria pular
     * o smoke inteiro. Testes de um provedor so chamam isto; os parametrizados
     * ganham o recorte pelo proprio dataProvider.
     */
    private function requireKey(Provider $provider): void
    {
        if (self::key($provider) === null) {
            $this->markTestSkipped(sprintf(
                'Defina %s para rodar o smoke live do %s.',
                $provider === Provider::Google ? 'GOOGLE_MAPS_KEY' : 'HERE_API_KEY',
                $provider->value,
            ));
        }
    }

    protected function defineEnvironment($app): void
    {
        $google = self::key(Provider::Google);
        $here = self::key(Provider::Here);

        if ($google === null && $here === null) {
            $this->markTestSkipped('Defina GOOGLE_MAPS_KEY e/ou HERE_API_KEY para rodar o smoke live.');
        }

        $app['config']->set('bee-maps.google.key', $google);
        $app['config']->set('bee-maps.here.api_key', $here);

        // So a estrategia `autosuggest` forcada usa este centro; no modo `auto`
        // uma busca sem coordenada vai para o /autocomplete, que nao precisa de
        // foco. Fica setado para o teste que exercita essa estrategia.
        $app['config']->set('bee-maps.here.autosuggest_center', '-23.5615,-46.6562');
    }

    /**
     * Sempre os dois provedores. Filtrar aqui pelas chaves do ambiente parecia
     * dar um recorte melhor no testdox, mas o PHPUnit resolve o dataProvider na
     * COLETA, antes do filtro de grupo: sem chave nenhuma o provider voltava
     * vazio e os 13 metodos parametrizados viravam erro ("Empty data set"), com
     * a suite inteira saindo 2 mesmo com --exclude-group live. O recorte por
     * provedor mora no requireKey() de cada teste, que pula como skipped.
     */
    public static function providers(): array
    {
        return [
            'google' => [Provider::Google],
            'here' => [Provider::Here],
        ];
    }

    #[DataProvider('providers')]
    public function test_autocomplete_without_coordinates_responds(Provider $provider): void
    {
        $this->requireKey($provider);

        $collection = $this->app->make(MapServiceFactory::class)
            ->autocomplete($provider)
            ->suggest(new AutocompleteRequest('Avenida Paulista'));

        $this->assertGreaterThan(0, $collection->count());
        $this->assertNotSame('', $collection->first()->description);
    }

    /**
     * A busca nacional do HERE: sem foco espacial nenhum, so `in=countryCode`.
     * E a combinacao que o /autosuggest recusa com 400, e por isso a unica
     * prova de que o /autocomplete aceita — o Http::fake nao valida query.
     */
    public function test_nationwide_here_autocomplete_responds_without_a_spatial_focus(): void
    {
        $this->requireKey(Provider::Here);

        $this->app['config']->set('bee-maps.here.autocomplete_strategy', 'autocomplete');
        $this->app['config']->set('bee-maps.here.autosuggest_center', null);

        $collection = $this->app->make(MapServiceFactory::class)
            ->autocomplete(Provider::Here)
            ->suggest(new AutocompleteRequest('Rua Blumenau'));

        $this->assertGreaterThan(0, $collection->count());
        $this->assertNotNull($collection->first()->place);
    }

    public function test_forced_here_autosuggest_responds_with_the_configured_center(): void
    {
        $this->requireKey(Provider::Here);

        $this->app['config']->set('bee-maps.here.autocomplete_strategy', 'autosuggest');

        $collection = $this->app->make(MapServiceFactory::class)
            ->autocomplete(Provider::Here)
            ->suggest(new AutocompleteRequest('Avenida Paulista'));

        $this->assertGreaterThan(0, $collection->count());
    }

    #[DataProvider('providers')]
    public function test_autocomplete_with_coordinates_and_the_default_radius_responds(Provider $provider): void
    {
        $this->requireKey($provider);

        // Esta e a chamada que dava 400 no HERE: near presente e radiusMeters no
        // default de 50000. Nenhum teste com Http::fake pega isso, porque o fake
        // nao valida a query.
        $collection = $this->app->make(MapServiceFactory::class)
            ->autocomplete($provider)
            ->suggest(new AutocompleteRequest('Avenida Paulista', new Coordinates(-23.5615, -46.6562)));

        $this->assertGreaterThan(0, $collection->count());
    }

    #[DataProvider('providers')]
    public function test_autocomplete_with_coordinates_and_no_radius_responds(Provider $provider): void
    {
        $this->requireKey($provider);

        $collection = $this->app->make(MapServiceFactory::class)
            ->autocomplete($provider)
            ->suggest(new AutocompleteRequest('Avenida Paulista', new Coordinates(-23.5615, -46.6562), null));

        $this->assertGreaterThan(0, $collection->count());
    }

    #[DataProvider('providers')]
    public function test_geocoding_responds(Provider $provider): void
    {
        $this->requireKey($provider);

        $collection = $this->app->make(MapServiceFactory::class)
            ->geocoding($provider)
            ->geocode('Avenida Paulista 1000, Sao Paulo');

        $this->assertGreaterThan(0, $collection->count());
        $this->assertNotNull($collection->first()->coordinates);
    }

    #[DataProvider('providers')]
    public function test_place_search_responds_with_a_structured_address(Provider $provider): void
    {
        $this->requireKey($provider);

        $collection = $this->app->make(MapServiceFactory::class)
            ->placeSearch($provider)
            ->search(new PlaceSearchRequest('farmacia', new Coordinates(-23.5615, -46.6562)));

        $this->assertGreaterThan(0, $collection->count());

        // D16 virando verificacao real: se o SKU Enterprise nao estiver
        // habilitado na conta, o Google devolve 403 ou vem sem componentes, e
        // este assert e o unico lugar onde isso aparece antes da producao.
        $this->assertNotNull($collection->first()->address->city);
    }

    #[DataProvider('providers')]
    public function test_a_simple_route_responds_with_a_polyline(Provider $provider): void
    {
        $this->requireKey($provider);

        $route = $this->app->make(MapServiceFactory::class)
            ->routing($provider)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6425),
                includePolyline: true,
            ));

        $this->assertGreaterThan(0, $route->distance->meters);
        $this->assertGreaterThan(0, $route->duration->seconds);
        $this->assertNotNull($route->polyline);
        // Decodificar de verdade: e o que prova que o formato do provider e o
        // que o decodificador registrado espera.
        $this->assertGreaterThan(1, count($route->polyline->coordinates()));
    }

    /**
     * ESTE e o teste que existe por causa da D18. A URL ja foi confirmada contra
     * a documentacao vigente e por sondagem HTTP; o que falta e o que so a chave
     * real responde: a conta Bee esta provisionada para a Waypoints Sequence API?
     *
     * 401 aqui = a chave nao tem acesso ao servico (provisionamento, nao URL).
     * 404 aqui = a ambiguidade `findsequence2` vs `findsequence.json` caiu para o
     * outro lado; trocar bee-maps.here.endpoints.findsequence e rodar de novo.
     */
    public function test_an_optimized_here_route_uses_the_configured_sequence_endpoint(): void
    {
        $this->requireKey(Provider::Here);

        $route = $this->app->make(MapServiceFactory::class)
            ->routing(Provider::Here)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6425),
                intermediates: [
                    new Coordinates(-23.5580, -46.6500),
                    new Coordinates(-23.5540, -46.6470),
                ],
                optimizeIntermediates: true,
                includeLegs: true,
            ));

        $this->assertCount(2, $route->optimizedOrder);
        $this->assertGreaterThan(0, $route->distance->meters);
    }

    public function test_an_optimized_here_route_on_two_wheels_responds(): void
    {
        $this->requireKey(Provider::Here);

        // O smoke so exercitava Drive. O findsequence e um motor legado, e os
        // quatro transport modes nao sao obviamente os mesmos do /v8/routes.
        $route = $this->app->make(MapServiceFactory::class)
            ->routing(Provider::Here)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6425),
                intermediates: [new Coordinates(-23.5580, -46.6500), new Coordinates(-23.5540, -46.6470)],
                mode: TravelMode::TwoWheeler,
                optimizeIntermediates: true,
            ));

        $this->assertCount(2, $route->optimizedOrder);
    }

    public function test_an_optimized_google_route_resolves_in_one_call(): void
    {
        $this->requireKey(Provider::Google);

        $route = $this->app->make(MapServiceFactory::class)
            ->routing(Provider::Google)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6425),
                intermediates: [
                    new Coordinates(-23.5580, -46.6500),
                    new Coordinates(-23.5540, -46.6470),
                ],
                optimizeIntermediates: true,
                includeLegs: true,
            ));

        $this->assertCount(2, $route->optimizedOrder);
        $this->assertCount(3, $route->legs);
    }

    #[DataProvider('providers')]
    public function test_a_2x2_matrix_responds(Provider $provider): void
    {
        $this->requireKey($provider);

        $matrix = $this->app->make(MapServiceFactory::class)
            ->routeMatrix($provider)
            ->matrix(new RouteMatrixRequest(
                [new Coordinates(-23.5615, -46.6562), new Coordinates(-23.5505, -46.6425)],
                [new Coordinates(-23.5580, -46.6500), new Coordinates(-23.5540, -46.6470)],
            ));

        $this->assertCount(4, $matrix);

        // Todos os quatro pares tem que existir e ser alcancaveis: sao pontos a
        // poucos quilometros um do outro em Sao Paulo.
        foreach ([0, 1] as $origin) {
            foreach ([0, 1] as $destination) {
                $entry = $matrix->entry($origin, $destination);

                $this->assertNotNull($entry, "Faltou a entrada ({$origin},{$destination}).");
                $this->assertTrue($entry->reachable);
                $this->assertGreaterThan(0, $entry->distance->meters);
            }
        }
    }

    public function test_a_matrix_above_625_elements_is_refused_by_google(): void
    {
        $this->expectException(\BeeDelivery\BeeMaps\Exceptions\MatrixTooLargeException::class);

        $points = [];

        for ($i = 0; $i < 26; $i++) {
            $points[] = new Coordinates(-23.5 - ($i / 10000), -46.6 - ($i / 10000));
        }

        $this->app->make(MapServiceFactory::class)
            ->routeMatrix(Provider::Google)
            ->matrix(new RouteMatrixRequest($points, $points));
    }

    /** @return list<Coordinates> */
    private function realStops(): array
    {
        return [
            new Coordinates(-23.5505, -46.6333),
            new Coordinates(-23.5870, -46.6570),
            new Coordinates(-23.5320, -46.6390),
        ];
    }

    /**
     * Alternativas so existem se o provider de fato devolver mais de uma rota —
     * e isso nenhum Http::fake responde.
     */
    #[DataProvider('providers')]
    public function test_live_a_route_with_alternatives_returns_more_than_one(Provider $provider): void
    {
        $this->requireKey($provider);

        $route = $this->app->make(MapServiceFactory::class)
            ->routing($provider)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6333),
                alternatives: 3,
            ));

        $this->assertNotSame([], $route->alternatives, 'Nenhuma alternativa: o parametro nao chegou no provider.');

        foreach ($route->alternatives as $alternative) {
            $this->assertGreaterThan(0, $alternative->distance->meters);
        }
    }

    #[DataProvider('providers')]
    public function test_live_without_asking_for_alternatives_a_single_route_comes_back(Provider $provider): void
    {
        $this->requireKey($provider);

        $route = $this->app->make(MapServiceFactory::class)
            ->routing($provider)
            ->route(new RouteRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5505, -46.6333),
            ));

        $this->assertSame([], $route->alternatives);
    }

    #[DataProvider('providers')]
    public function test_live_optimizes_with_a_fixed_end(Provider $provider): void
    {
        $this->requireKey($provider);

        $result = $this->app->make(MapServiceFactory::class)
            ->routeOptimization($provider)
            ->optimize(new OptimizeWaypointsRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5980, -46.6860),
                intermediates: $this->realStops(),
            ));

        $this->assertCount(3, $result->order);
        $this->assertGreaterThan(0, $result->distance->meters);
    }

    #[DataProvider('providers')]
    public function test_live_optimizes_an_open_tour(Provider $provider): void
    {
        $this->requireKey($provider);

        $result = $this->app->make(MapServiceFactory::class)
            ->routeOptimization($provider)
            ->optimize(new OptimizeWaypointsRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                intermediates: $this->realStops(),
            ));

        $this->assertCount(3, $result->order);
        $this->assertGreaterThan(0, $result->distance->meters);
    }

    #[DataProvider('providers')]
    public function test_live_optimizes_with_a_return_to_origin(Provider $provider): void
    {
        $this->requireKey($provider);

        $origin = new Coordinates(-23.5615, -46.6562);

        $result = $this->app->make(MapServiceFactory::class)
            ->routeOptimization($provider)
            ->optimize(new OptimizeWaypointsRequest(
                origin: $origin,
                destination: $origin,
                intermediates: $this->realStops(),
            ));

        $this->assertCount(3, $result->order);
    }

    #[DataProvider('providers')]
    public function test_live_the_distance_objective_is_accepted(Provider $provider): void
    {
        $this->requireKey($provider);

        // No HERE vira improveFor=distance; no Google, a matriz + TSP local.
        // A estrategia fleet_routing NAO entra no smoke: a service account nao
        // existe neste ambiente.
        $result = $this->app->make(MapServiceFactory::class)
            ->routeOptimization($provider)
            ->optimize(new OptimizeWaypointsRequest(
                origin: new Coordinates(-23.5615, -46.6562),
                destination: new Coordinates(-23.5980, -46.6860),
                intermediates: $this->realStops(),
                objective: OptimizationObjective::MinDistance,
            ));

        $this->assertCount(3, $result->order);
        $this->assertGreaterThan(0, $result->distance->meters);
    }
}
