<?php

namespace BeeDelivery\BeeMaps\Providers\Google\Mappers;

use BeeDelivery\BeeMaps\DTOs\Responses\Route;
use BeeDelivery\BeeMaps\DTOs\Responses\RouteLeg;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\Polyline\GoogleEncodedPolylineDecoder;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;
use BeeDelivery\BeeMaps\Support\ValueObjects\Distance;
use BeeDelivery\BeeMaps\Support\ValueObjects\Duration;
use BeeDelivery\BeeMaps\Support\ValueObjects\Polyline;

final class GoogleRouteResponseMapper
{
    public function __construct(
        private readonly GoogleEncodedPolylineDecoder $decoder = new GoogleEncodedPolylineDecoder(),
    ) {
    }

    /**
     * @param bool $includeLegs          Pernas sao opt-in no contrato do pacote. O field
     *                                   mask ja omite `routes.legs` quando ninguem pediu,
     *                                   mas honrar o flag aqui tambem e o que garante a
     *                                   simetria com o HERE: sem includeLegs, NENHUM
     *                                   provider devolve pernas, independente do que a
     *                                   resposta trouxer.
     * @param bool $didOptimize          Mesma razao para optimizedOrder, que o DTO Route
     *                                   documenta como "vazio sem otimizacao".
     * @param bool $askedForAlternatives Mesma razao de $includeLegs: sem pedido, NENHUM
     *                                   provider devolve alternativas, ainda que a resposta
     *                                   traga — simetria antes de generosidade.
     */
    public function toRoute(
        array $response,
        bool $includeLegs,
        bool $didOptimize = false,
        bool $askedForAlternatives = false,
    ): Route {
        $routes = $response['routes'] ?? [];
        $route = $routes[0] ?? null;

        if ($route === null) {
            throw new InvalidRequestException('O Google nao devolveu rota para os pontos informados.');
        }

        return new Route(
            distance: new Distance((int) ($route['distanceMeters'] ?? 0)),
            duration: new Duration($this->seconds($route['duration'] ?? null)),
            polyline: $this->polyline($route['polyline']['encodedPolyline'] ?? null),
            legs: $includeLegs ? array_map($this->leg(...), $route['legs'] ?? []) : [],
            optimizedOrder: $didOptimize
                ? array_map('intval', $route['optimizedIntermediateWaypointIndex'] ?? [])
                : [],
            alternatives: $askedForAlternatives
                ? array_map(
                    fn (array $other) => $this->simpleRoute($other, $includeLegs),
                    array_values(array_slice($routes, 1)),
                )
                : [],
        );
    }

    /**
     * Alternativa nao carrega alternativas proprias nem ordem otimizada: a ordem
     * dos intermediarios e uma so para a requisicao inteira.
     */
    private function simpleRoute(array $route, bool $includeLegs): Route
    {
        return new Route(
            distance: new Distance((int) ($route['distanceMeters'] ?? 0)),
            duration: new Duration($this->seconds($route['duration'] ?? null)),
            polyline: $this->polyline($route['polyline']['encodedPolyline'] ?? null),
            legs: $includeLegs ? array_map($this->leg(...), $route['legs'] ?? []) : [],
        );
    }

    private function leg(array $leg): RouteLeg
    {
        return new RouteLeg(
            origin: $this->point($leg['startLocation']['latLng'] ?? []),
            destination: $this->point($leg['endLocation']['latLng'] ?? []),
            distance: new Distance((int) ($leg['distanceMeters'] ?? 0)),
            duration: new Duration($this->seconds($leg['duration'] ?? null)),
            polyline: $this->polyline($leg['polyline']['encodedPolyline'] ?? null),
        );
    }

    private function point(array $latLng): Coordinates
    {
        return new Coordinates((float) ($latLng['latitude'] ?? 0), (float) ($latLng['longitude'] ?? 0));
    }

    private function polyline(?string $encoded): ?Polyline
    {
        return $encoded !== null ? new Polyline($encoded, $this->decoder) : null;
    }

    /**
     * O Google serializa duracao como string de protobuf ("1830s"); o HERE manda
     * inteiro. A normalizacao acontece aqui, na fronteira do mapper.
     */
    private function seconds(string|int|null $value): int
    {
        return match (true) {
            $value === null => 0,
            is_int($value) => $value,
            default => (int) rtrim($value, 's'),
        };
    }
}
