<?php

namespace BeeDelivery\BeeMaps\Tests\Unit\Providers\Google;

use BeeDelivery\BeeMaps\Exceptions\MissingCredentialsException;
use BeeDelivery\BeeMaps\Exceptions\ProviderAuthenticationException;
use BeeDelivery\BeeMaps\Providers\Google\Optimization\ServiceAccountToken;
use BeeDelivery\BeeMaps\Tests\TestCase;

/**
 * O fetcher injetado e o mesmo seam que a interface AccessToken abre para a
 * FleetRoutingStrategy: sem ele nao da para exercitar nada aqui, porque
 * google/apiclient e `suggest` e nao esta no vendor da suite.
 */
final class ServiceAccountTokenTest extends TestCase
{
    /** @var array<string, string> */
    private const CREDENTIALS = [
        'project_id' => 'bee-maps-test',
        'private_key' => '-----BEGIN PRIVATE KEY-----fake-----END PRIVATE KEY-----',
        'client_email' => 'fleet@bee-maps-test.iam.gserviceaccount.com',
    ];

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function token(array $responses, ?int &$calls = null): ServiceAccountToken
    {
        $calls = 0;

        return new ServiceAccountToken(
            self::CREDENTIALS,
            'https://www.googleapis.com/auth/cloud-platform',
            function () use ($responses, &$calls): array {
                $response = $responses[$calls] ?? end($responses);
                $calls++;

                return $response;
            },
        );
    }

    public function test_a_valid_token_is_fetched_once_and_reused(): void
    {
        $token = $this->token([['access_token' => 'abc', 'expires_in' => 3600]], $calls);

        $this->assertSame('abc', $token->value());
        $this->assertSame('abc', $token->value());
        $this->assertSame('abc', $token->value());

        $this->assertSame(1, $calls, 'A troca OAuth deveria acontecer uma vez so.');
    }

    /**
     * Um token que vence dentro da margem nao e reaproveitado: a chamada real
     * ainda esta por vir, e vencer no meio dela custa um 401 evitavel.
     */
    public function test_a_token_inside_the_safety_margin_is_refetched(): void
    {
        $token = $this->token([
            ['access_token' => 'quase-vencido', 'expires_in' => 5],
            ['access_token' => 'novo', 'expires_in' => 3600],
        ], $calls);

        $this->assertSame('quase-vencido', $token->value());
        $this->assertSame('novo', $token->value());

        $this->assertSame(2, $calls);
    }

    /**
     * Sem expires_in nao da para saber ate quando vale; cachear "por garantia"
     * trocaria uma troca OAuth barata por um 401 no meio da rota.
     */
    public function test_a_token_without_an_expiry_is_never_cached(): void
    {
        $token = $this->token([['access_token' => 'sem-validade']], $calls);

        $token->value();
        $token->value();

        $this->assertSame(2, $calls);
    }

    public function test_an_empty_token_still_fails_with_a_local_cause(): void
    {
        $token = $this->token([['access_token' => '', 'expires_in' => 3600]]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->expectExceptionMessageMatches('/access_token/');

        $token->value();
    }

    public function test_missing_credentials_are_rejected_on_construction(): void
    {
        $this->expectException(MissingCredentialsException::class);

        new ServiceAccountToken(
            ['project_id' => 'bee-maps-test'],
            'https://www.googleapis.com/auth/cloud-platform',
            fn (): array => ['access_token' => 'abc', 'expires_in' => 3600],
        );
    }
}
