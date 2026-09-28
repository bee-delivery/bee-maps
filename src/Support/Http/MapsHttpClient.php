<?php

namespace BeeDelivery\BeeMaps\Support\Http;

use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Enums\Service;
use BeeDelivery\BeeMaps\Exceptions\ProviderAuthenticationException;
use BeeDelivery\BeeMaps\Exceptions\ProviderRateLimitException;
use BeeDelivery\BeeMaps\Exceptions\ProviderRequestException;
use BeeDelivery\BeeMaps\Exceptions\ProviderUnavailableException;
use BeeDelivery\BeeMaps\Support\CredentialRedaction;
use BeeDelivery\BeeMaps\Support\Events\MapRequestCompleted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

final class MapsHttpClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {
    }

    /**
     * Acumulador da operacao em curso: null fora de operacao(). Guardar estado
     * mutavel num singleton so e aceitavel porque o escopo e sincrono e o
     * finally de operacao() sempre o limpa — inclusive quando a chamada estoura.
     *
     * @var array{calls: int, durationMs: float, status: int}|null
     */
    /** @var array{calls: int, durationMs: float, status: int}|null */
    private ?array $currentOperation = null;

    public function get(Provider $provider, Service $service, string $url, array $query = [], array $headers = []): array
    {
        return $this->send($provider, $service, fn () => $this->pending($headers)->get($url, $this->serializeQuery($query)));
    }

    public function post(Provider $provider, Service $service, string $url, array $payload, array $headers = []): array
    {
        return $this->send($provider, $service, fn () => $this->pending($headers)->post($url, $payload));
    }

    /**
     * Agrupa varias chamadas HTTP como UMA operacao logica: um unico
     * MapRequestCompleted, com a soma das duracoes, a contagem de chamadas em
     * upstreamCalls e o status da ultima.
     *
     * Existe porque no HERE uma rota otimizada custa duas chamadas upstream
     * (findsequence + /v8/routes). Sem o agrupamento, a comparacao de latencia
     * da POC veria duas chamadas rapidas do HERE contra uma do Google e
     * concluiria o oposto do que os dados dizem.
     */
    public function operation(Provider $provider, Service $service, callable $steps): mixed
    {
        $previous = $this->currentOperation;
        $this->currentOperation = ['calls' => 0, 'durationMs' => 0.0, 'status' => 0];

        try {
            return $steps();
        } finally {
            $accumulated = $this->takeOperation($previous);

            if ($accumulated['calls'] > 0) {
                $this->events->dispatch(new MapRequestCompleted(
                    provider: $provider,
                    service: $service,
                    httpStatus: $accumulated['status'],
                    durationMs: round($accumulated['durationMs'], 2),
                    upstreamCalls: $accumulated['calls'],
                ));
            }
        }
    }

    /**
     * Fecha o acumulador e restaura o anterior. E metodo proprio porque quem
     * incrementa e o record(), chamado la dentro do callable: lido inline, o
     * acumulador ainda parece o literal zerado de duas linhas acima.
     *
     * @param  array{calls: int, durationMs: float, status: int}|null $previous
     * @return array{calls: int, durationMs: float, status: int}
     */
    private function takeOperation(?array $previous): array
    {
        $accumulated = $this->currentOperation ?? ['calls' => 0, 'durationMs' => 0.0, 'status' => 0];
        $this->currentOperation = $previous;

        return $accumulated;
    }

    private function pending(array $headers): PendingRequest
    {
        return $this->http
            ->withHeaders($headers + ['Accept' => 'application/json'])
            ->timeout($this->config['timeout'])
            ->connectTimeout($this->config['connect_timeout'])
            ->retry($this->config['attempts'], $this->config['retry_delay_ms'], $this->shouldRetry(...), throw: false);
    }

    /**
     * PHP/Guzzle serializam array em query string com indices ("in[0]=a&in[1]=b"),
     * formato que a maioria das APIs de terceiros nao aceita para repetir uma
     * mesma chave (ex.: o filtro "in" do HERE precisa de "in=a&in=b"). Serializa
     * manualmente cada valor de array como a mesma chave repetida.
     */
    private function serializeQuery(array $parameters): string
    {
        $parts = [];

        foreach ($parameters as $key => $value) {
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    /**
     * So vale a pena tentar de novo em falha de conexao (timeout, DNS, etc.) ou em
     * resposta que sinaliza indisponibilidade temporaria (429, 5xx). Repetir um 401/403
     * apenas dobra a carga no provider exatamente quando ele esta bloqueando a chamada.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function send(Provider $provider, Service $service, callable $call): array
    {
        $start = microtime(true);

        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            $this->record($provider, $service, 0, (microtime(true) - $start) * 1000);

            throw new ProviderUnavailableException($provider, $service, $this->redactCredentials($e->getMessage()));
        }

        $this->record($provider, $service, $response->status(), (microtime(true) - $start) * 1000);

        if ($response->failed()) {
            throw $this->translateError($provider, $service, $response);
        }

        return $response->json() ?? [];
    }

    /**
     * Fora de uma operacao, cada chamada vira um evento. Dentro, acumula: quem
     * emite e o finally de operacao().
     */
    private function record(Provider $provider, Service $service, int $status, float $elapsedMs): void
    {
        if ($this->currentOperation !== null) {
            $this->currentOperation['calls']++;
            $this->currentOperation['durationMs'] += $elapsedMs;
            $this->currentOperation['status'] = $status;

            return;
        }

        $this->events->dispatch(new MapRequestCompleted(
            provider: $provider,
            service: $service,
            httpStatus: $status,
            durationMs: round($elapsedMs, 2),
        ));
    }

    private function translateError(Provider $provider, Service $service, Response $response): ProviderRequestException
    {
        $status = $response->status();
        $body = $response->json();

        // O computeRouteMatrix do Google embrulha o erro num array
        // ([{"error": {...}}]), diferente de todos os outros endpoints. Sem este
        // fallback a mensagem que explica a falha — "the product of the number of
        // origins and destinations must be <= 625" — se perde, e o consumidor
        // recebe um "Bad Request" sem causa.
        $error = $body['error'] ?? $body[0]['error'] ?? null;

        $code = $error['status'] ?? $error['code'] ?? null;
        $message = $this->redactCredentials($error['message'] ?? ($response->reason() ?: 'falha na chamada ao provider'));

        // Preserva null: providerCode() é ?string justamente para distinguir
        // "o provider não mandou código" de "mandou um código". Um (string) aqui
        // transformaria todo ausente em '' e quebraria essa distinção.
        $code = $code !== null ? (string) $code : null;

        return match (true) {
            $status === 401, $status === 403 => new ProviderAuthenticationException($provider, $service, $message, $status, $code),
            $status === 429 => new ProviderRateLimitException($provider, $service, $message, $status, $code),
            $status >= 500 => new ProviderUnavailableException($provider, $service, $message, $status, $code),
            default => new ProviderRequestException($provider, $service, $message, $status, $code),
        };
    }

    private function redactCredentials(string $text): string
    {
        return CredentialRedaction::redact($text);
    }
}
