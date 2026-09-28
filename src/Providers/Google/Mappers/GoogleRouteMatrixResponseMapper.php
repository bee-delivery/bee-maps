<?php

namespace BeeDelivery\BeeMaps\Providers\Google\Mappers;

use BeeDelivery\BeeMaps\DTOs\Responses\RouteMatrixEntry;
use BeeDelivery\BeeMaps\DTOs\Responses\RouteMatrixEntryCollection;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Enums\Service;
use BeeDelivery\BeeMaps\Exceptions\ProviderRequestException;
use BeeDelivery\BeeMaps\Support\CredentialRedaction;
use BeeDelivery\BeeMaps\Support\ValueObjects\Distance;
use BeeDelivery\BeeMaps\Support\ValueObjects\Duration;

final class GoogleRouteMatrixResponseMapper
{
    /**
     * @param int $origins  Quantas origens a requisicao pediu.
     * @param int $destinations Quantos destinos a requisicao pediu.
     *
     * @throws ProviderRequestException quando a resposta nao descreve a grade inteira
     */
    public function toCollection(array $response, int $origins, int $destinations): RouteMatrixEntryCollection
    {
        $entries = [];
        $seen = [];

        // Erro no topo do corpo, sem embrulho de array. Chega com HTTP 200, entao
        // o traduzirErro do MapsHttpClient nunca roda — e sem isto o corpo inteiro
        // e iterado como se fosse elemento de matriz. O MapsHttpClient ja trata as
        // duas formas (`$body['error'] ?? $body[0]['error']`); aqui e o espelho.
        if (isset($response['error'])) {
            throw $this->apiError($response['error'], 'O Google devolveu erro no corpo da matriz com HTTP 200');
        }

        foreach ($response as $element) {
            if (! is_array($element)) {
                continue;
            }

            // O computeRouteMatrix e server-streaming: uma falha depois do stream
            // comecar chega como elemento de erro no meio do array, com HTTP 200.
            // Sem isto o elemento cai em (0,0) — sem indices, com 0 metros e
            // marcado como alcancavel — e a colecao sobrescreve o par verdadeiro.
            if (isset($element['error'])) {
                throw $this->apiError($element['error'], 'O Google interrompeu a matriz no meio do stream');
            }

            $origin = (int) ($element['originIndex'] ?? 0);
            $destination = (int) ($element['destinationIndex'] ?? 0);

            // Contar elementos nao prova que a grade esta completa: indice fora
            // da faixa pedida e par repetido somam certo e deixam buraco, porque
            // a colecao indexa por par e o repetido sobrescreve o anterior.
            if ($origin < 0 || $origin >= $origins || $destination < 0 || $destination >= $destinations) {
                throw new ProviderRequestException(
                    Provider::Google,
                    Service::RouteMatrix,
                    sprintf(
                        'O Google devolveu o par (%d,%d), fora da faixa pedida de %d origens por %d destinos.',
                        $origin,
                        $destination,
                        $origins,
                        $destinations,
                    ),
                    200,
                );
            }

            $pair = $origin . ':' . $destination;

            if (isset($seen[$pair])) {
                throw new ProviderRequestException(
                    Provider::Google,
                    Service::RouteMatrix,
                    sprintf('O Google devolveu o par (%d,%d) duplicado.', $origin, $destination),
                    200,
                );
            }

            $seen[$pair] = true;

            // So ROUTE_EXISTS explicito conta como alcancavel: proto3 omite o
            // default do enum, e o default aqui e UNSPECIFIED, nao ROUTE_EXISTS.
            $reachable = ($element['condition'] ?? null) === 'ROUTE_EXISTS';

            $entries[] = new RouteMatrixEntry(
                originIndex: $origin,
                destinationIndex: $destination,
                distance: new Distance($reachable ? (int) ($element['distanceMeters'] ?? 0) : 0),
                duration: new Duration($reachable ? $this->seconds($element['duration'] ?? null) : 0),
                reachable: $reachable,
            );
        }

        // Com todos os pares distintos e dentro da faixa, a contagem certa passa
        // a significar grade completa — e so agora ela prova alguma coisa.
        if (count($entries) !== $origins * $destinations) {
            throw new ProviderRequestException(
                Provider::Google,
                Service::RouteMatrix,
                sprintf(
                    'Matriz incompleta: o Google devolveu %d de %d pares. '
                    . 'Entregar a matriz parcial esconderia pares que o chamador pediu.',
                    count($entries),
                    $origins * $destinations,
                ),
                200,
            );
        }

        return new RouteMatrixEntryCollection(...$entries);
    }

    /**
     * O erro do Google costuma ser um objeto, mas nao ha garantia: aceitar so
     * array fazia a guarda pular um `error` de outro tipo, e o corpo voltava a
     * ser lido como elemento de matriz — o par fantasma que a guarda impede.
     *
     * @param array<string, mixed>|mixed $error
     */
    private function apiError(mixed $error, string $context): ProviderRequestException
    {
        $detail = is_array($error) ? $error : ['message' => (string) $error];

        return new ProviderRequestException(
            Provider::Google,
            Service::RouteMatrix,
            // Texto cru de provider pode ecoar a URL da requisicao, e a query do
            // Google leva `key=`. Sem redigir aqui, a chave vaza para o log pelo
            // caminho que nao passa pelo MapsHttpClient.
            CredentialRedaction::redact($context . ': ' . ($detail['message'] ?? 'erro sem mensagem')),
            200,
            // Mesmo fallback do MapsHttpClient: sem ele, o mesmo erro upstream
            // produz providerCode diferente conforme o status HTTP.
            isset($detail['status']) ? (string) $detail['status']
                : (isset($detail['code']) ? (string) $detail['code'] : null),
        );
    }

    /**
     * O Google serializa duracao como string de protobuf ("490s").
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
