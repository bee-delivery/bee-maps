<?php

namespace BeeDelivery\BeeMaps\Tests\Unit\Support;

use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;
use BeeDelivery\BeeMaps\Support\CountryCode;
use BeeDelivery\BeeMaps\Tests\TestCase;

final class CountryCodeTest extends TestCase
{
    public function test_toAlpha3_converts_alpha2(): void
    {
        $this->assertSame('BRA', CountryCode::toAlpha3('BR'));
    }

    public function test_toAlpha3_accepts_lowercase(): void
    {
        $this->assertSame('BRA', CountryCode::toAlpha3('br'));
    }

    public function test_toAlpha3_passes_alpha3_through(): void
    {
        $this->assertSame('BRA', CountryCode::toAlpha3('BRA'));
    }

    public function test_toAlpha3_throws_on_an_invalid_code(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageMatches('/XX/');

        CountryCode::toAlpha3('XX');
    }

    public function test_toAlpha3_throws_on_an_invalid_alpha3_code(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageMatches('/XXX/');

        CountryCode::toAlpha3('XXX');
    }

    /**
     * O caso real: um typo em `countries: ['BRZ']` passava batido e so estourava
     * como 400 generico do provider, longe de quem escreveu o typo.
     */
    public function test_toAlpha3_throws_on_a_three_letter_typo(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageMatches('/BRZ/');

        CountryCode::toAlpha3('BRZ');
    }

    public function test_toAlpha2_converts_alpha3(): void
    {
        $this->assertSame('BR', CountryCode::toAlpha2('BRA'));
    }

    public function test_toAlpha2_passes_alpha2_through(): void
    {
        $this->assertSame('BR', CountryCode::toAlpha2('BR'));
    }

    public function test_toAlpha2_accepts_lowercase(): void
    {
        $this->assertSame('BR', CountryCode::toAlpha2('bra'));
    }

    public function test_toAlpha2_throws_on_an_invalid_alpha2_code(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageMatches('/XX/');

        CountryCode::toAlpha2('XX');
    }

    public function test_toAlpha2_throws_on_an_invalid_alpha3_code(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageMatches('/XXX/');

        CountryCode::toAlpha2('XXX');
    }

    public function test_round_trips_every_code_in_the_table(): void
    {
        foreach (['BR', 'US', 'MX', 'DE', 'JP', 'ZW'] as $alpha2) {
            $alpha3 = CountryCode::toAlpha3($alpha2);
            $this->assertSame($alpha2, CountryCode::toAlpha2($alpha3));
        }
    }
}
