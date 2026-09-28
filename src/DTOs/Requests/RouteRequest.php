<?php

namespace BeeDelivery\BeeMaps\DTOs\Requests;

use BeeDelivery\BeeMaps\Enums\TravelMode;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\ValueObjects\Coordinates;

final readonly class RouteRequest
{
    /** @var list<Coordinates> */
    public array $intermediates;

    /**
     * @param array<int, Coordinates> $intermediates         Waypoints entre origem e destino.
     * @param bool              $optimizeIntermediates Deixa o provider reordenar os
     *                                                 intermediarios. No HERE custa uma
     *                                                 chamada upstream a mais.
     * @param int               $alternatives          Quantas rotas ADICIONAIS pedir, de 0 a 6.
     *                                                 Zero (default) mantem uma rota so. E um
     *                                                 PEDIDO, nao uma garantia: os dois providers
     *                                                 devolvem quantas encontrarem, e podem
     *                                                 devolver menos. O Google so aceita
     *                                                 liga/desliga, entao la o numero vira
     *                                                 `computeAlternativeRoutes: true` e a
     *                                                 quantidade fica a criterio dele.
     *                                                 Custa UMA chamada upstream, nao N.
     *                                                 As rotas extras chegam em `Route::$alternatives`
     *                                                 — o pacote nao escolhe entre elas, porque
     *                                                 "mais curta" ou "mais rapida" e regra de
     *                                                 quem chama.
     */
    public function __construct(
        public Coordinates $origin,
        public Coordinates $destination,
        array $intermediates = [],
        public TravelMode $mode = TravelMode::Drive,
        public bool $optimizeIntermediates = false,
        public bool $includePolyline = false,
        public bool $includeLegs = false,
        public int $alternatives = 0,
    ) {
        // Mesma razao do OptimizeWaypointsRequest e do RouteMatrixRequest:
        // array_filter preserva chaves, e uma lista com buraco quebra os dois
        // providers por caminhos diferentes — o json_encode do Google emite
        // objeto em vez de lista, e o mapper do HERE indexa 0..N-1 direto no
        // array quando reordena, batendo em chave inexistente.
        $this->intermediates = array_values($intermediates);

        // Teto do HERE, medido em 2026-09-23: `alternatives=7` responde 400 com
        // "Number of alternatives must be <= 6". Recusar aqui mantem os dois
        // providers com o mesmo contrato, em vez de deixar um 400 vazar so num deles.
        if ($alternatives < 0 || $alternatives > 6) {
            throw new InvalidRequestException(sprintf(
                'alternatives deve ficar entre 0 e 6, recebido %d.',
                $alternatives,
            ));
        }
    }
}
