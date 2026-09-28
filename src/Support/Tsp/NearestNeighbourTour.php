<?php

namespace BeeDelivery\BeeMaps\Support\Tsp;

use BeeDelivery\BeeMaps\DTOs\Responses\RouteMatrixEntry;
use BeeDelivery\BeeMaps\DTOs\Responses\RouteMatrixEntryCollection;
use BeeDelivery\BeeMaps\Enums\OptimizationObjective;
use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\ValueObjects\Distance;
use BeeDelivery\BeeMaps\Support\ValueObjects\Duration;

/**
 * Vizinho-mais-proximo sobre uma matriz de rotas. Nao conhece provider nem
 * contrato: recebe a matriz e indices, devolve indices. E isso que o torna
 * testavel com matriz montada a mao, sem HTTP.
 *
 * A diagonal nao precisa de filtro: o laco so consulta entry($current, $candidate)
 * com $candidate entre os pendentes, e $current nunca esta la. O $end tambem fica
 * fora dos pendentes — por isso o ponto duplicado do caso `destination = origin`
 * nao vira candidato, sem depender de medir 0 metros para ser reconhecido.
 */
final class NearestNeighbourTour
{
    /**
     * @param int  $origin Indice do ponto de partida na matriz.
     * @param ?int $end    Indice do ponto final, ou null para tour aberto. Pode
     *                     ser igual a $origin: e assim que "volta ao ponto de
     *                     partida" se expressa.
     *
     * @throws InvalidRequestException quando nao ha como avancar ou a perna final
     *         nao existe na matriz
     */
    public function tour(
        RouteMatrixEntryCollection $matrix,
        int $origin,
        ?int $end,
        OptimizationObjective $objective,
    ): TourResult {
        $pending = $this->pointsToVisit($matrix, $origin, $end);

        $order = [];
        $meters = 0;
        $seconds = 0;
        $current = $origin;

        while ($pending !== []) {
            $next = $this->nearest($matrix, $current, $pending, $objective);
            $leg = $matrix->entry($current, $next);

            $meters += $leg->distance->meters;
            $seconds += $leg->duration->seconds;
            $order[] = $next;

            $pending = array_values(array_filter(
                $pending,
                static fn (int $node): bool => $node !== $next,
            ));

            $current = $next;
        }

        if ($end !== null) {
            $leg = $matrix->entry($current, $end);

            // Fabricar 0 aqui inventaria distancia. Medida ausente e erro.
            if ($leg === null || ! $leg->reachable) {
                throw new InvalidRequestException(sprintf(
                    'A matriz nao traz rota entre o ponto %d e o destino %d; '
                    . 'somar zero inventaria a perna final.',
                    $current,
                    $end,
                ));
            }

            $meters += $leg->distance->meters;
            $seconds += $leg->duration->seconds;
        }

        return new TourResult($order, new Distance($meters), new Duration($seconds));
    }

    /**
     * @return list<int>
     */
    private function pointsToVisit(RouteMatrixEntryCollection $matrix, int $origin, ?int $end): array
    {
        $nodes = [];

        foreach ($matrix as $entry) {
            $nodes[$entry->originIndex] = true;
            $nodes[$entry->destinationIndex] = true;
        }

        $pending = array_filter(
            array_keys($nodes),
            static fn (int $node): bool => $node !== $origin && $node !== $end,
        );

        // sort() ja reindexa: nao cabe array_values depois dele.
        sort($pending);

        return $pending;
    }

    /**
     * @param list<int> $pending
     */
    private function nearest(
        RouteMatrixEntryCollection $matrix,
        int $current,
        array $pending,
        OptimizationObjective $objective,
    ): int {
        $chosen = null;
        $best = null;

        foreach ($pending as $candidate) {
            $leg = $matrix->entry($current, $candidate);

            // Par sem rota nao entra na escolha: usar a medida zerada que o
            // contrato da matriz poe em reachable=false faria dele o mais
            // proximo de todos.
            if ($leg === null || ! $leg->reachable) {
                continue;
            }

            $cost = $this->cost($leg, $objective);

            if ($best === null || $cost < $best) {
                $best = $cost;
                $chosen = $candidate;
            }
        }

        return $chosen ?? throw new InvalidRequestException(sprintf(
            'Nenhum dos %d pontos restantes e alcancavel a partir do ponto %d; '
            . 'ordem que atravessa trecho sem rota e pior que erro.',
            count($pending),
            $current,
        ));
    }

    private function cost(RouteMatrixEntry $leg, OptimizationObjective $objective): int
    {
        return match ($objective) {
            OptimizationObjective::MinDistance => $leg->distance->meters,
            OptimizationObjective::MinTravelTime => $leg->duration->seconds,
        };
    }
}
