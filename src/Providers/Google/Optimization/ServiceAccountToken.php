<?php

namespace BeeDelivery\BeeMaps\Providers\Google\Optimization;

use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\Enums\Service;
use BeeDelivery\BeeMaps\Exceptions\ConfigurationException;
use BeeDelivery\BeeMaps\Exceptions\MissingCredentialsException;
use BeeDelivery\BeeMaps\Exceptions\ProviderAuthenticationException;
use Closure;
use Google\Client;

/**
 * OAuth de service account para a Cloud Fleet Routing — o unico endpoint do
 * pacote que nao aceita chave de API.
 *
 * O google/apiclient entra no composer.json como `suggest`, nao como `require`:
 * arrasta google/auth, firebase/php-jwt e Guzzle para todo mundo que instalar o
 * pacote, por um caminho que a estrategia default (matrix_tsp) nao usa.
 */
final class ServiceAccountToken implements AccessToken
{
    /**
     * Margem de seguranca: um token que vence nos proximos segundos e tratado
     * como vencido. A chamada real ainda esta por vir, e vencer no meio dela
     * custa um 401 que a renovacao antecipada evita de graca.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private ?string $cached = null;

    private float $expiresAt = 0.0;

    /** @var Closure(): array<string, mixed> */
    private readonly Closure $fetcher;

    /**
     * @param array<string, mixed>               $credentials Conteudo do JSON de service account.
     * @param ?Closure(): array<string, mixed>   $fetcher     Troca OAuth. Nulo usa o
     *                                                        google/apiclient; injetar e o
     *                                                        mesmo seam que a interface
     *                                                        AccessToken abre para a
     *                                                        FleetRoutingStrategy, e e o que
     *                                                        torna o cache testavel sem a
     *                                                        dependencia opcional no vendor.
     */
    public function __construct(
        private readonly array $credentials,
        private readonly string $scope,
        ?Closure $fetcher = null,
    ) {
        if ($fetcher === null && ! class_exists(Client::class)) {
            throw new ConfigurationException(
                'A estrategia fleet_routing precisa do pacote google/apiclient, que o '
                . 'bee-maps apenas sugere: rode `composer require google/apiclient`. '
                . 'Se nao quiser a dependencia, deixe bee-maps.google.route_optimization'
                . '.min_distance_api em "matrix_tsp", que e o default e nao precisa dela.',
            );
        }

        foreach (['project_id', 'private_key', 'client_email'] as $required) {
            if (empty($this->credentials[$required])) {
                throw MissingCredentialsException::make(
                    Provider::Google,
                    'bee-maps.google.route_optimization.service_account.' . $required,
                );
            }
        }

        $this->fetcher = $fetcher ?? $this->googleClientFetcher();
    }

    /**
     * O token vale ~1h. Sem cache, toda chamada de fleet_routing pagava uma
     * troca OAuth completa antes da chamada real — latencia e carga por nada.
     */
    public function value(): string
    {
        if ($this->cached !== null && microtime(true) < $this->expiresAt) {
            return $this->cached;
        }

        $response = ($this->fetcher)();
        $token = $response['access_token'] ?? null;

        // Sem isto, um token vazio viraria header "Bearer " e o erro voltaria do
        // Google como 401 generico, sem indicar que a causa e a credencial local.
        if (! is_string($token) || $token === '') {
            throw new ProviderAuthenticationException(
                Provider::Google,
                Service::RouteOptimization,
                'A service account nao devolveu access_token para a Cloud Fleet Routing.',
                401,
            );
        }

        $this->cache($token, $response['expires_in'] ?? null);

        return $token;
    }

    /**
     * Sem `expires_in` utilizavel nao da para saber ate quando o token vale, e
     * cachear por garantia trocaria uma troca OAuth barata por um 401 no meio
     * da rota. Nesse caso o cache simplesmente nao guarda.
     */
    private function cache(string $token, mixed $expiresIn): void
    {
        if (! is_numeric($expiresIn)) {
            $this->cached = null;
            $this->expiresAt = 0.0;

            return;
        }

        $lifetime = (int) $expiresIn - self::EXPIRY_MARGIN_SECONDS;

        if ($lifetime <= 0) {
            $this->cached = null;
            $this->expiresAt = 0.0;

            return;
        }

        $this->cached = $token;
        $this->expiresAt = microtime(true) + $lifetime;
    }

    /**
     * @return Closure(): array<string, mixed>
     */
    private function googleClientFetcher(): Closure
    {
        return function (): array {
            $client = new Client();
            $client->setAuthConfig($this->credentials);
            $client->addScope($this->scope);

            return $client->fetchAccessTokenWithAssertion();
        };
    }
}
