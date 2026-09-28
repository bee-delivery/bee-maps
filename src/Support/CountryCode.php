<?php

namespace BeeDelivery\BeeMaps\Support;

use BeeDelivery\BeeMaps\Exceptions\InvalidRequestException;

/**
 * Conversao de codigos de pais ISO 3166-1 entre alpha-2 e alpha-3.
 *
 * E utilitario de pacote (nao especifico de nenhum provider): o Google
 * consome alpha-2 e o HERE consome alpha-3, entao um caller pode entregar
 * qualquer uma das duas formas e cada provider converte para o que precisa.
 */
final class CountryCode
{
    /**
     * ISO 3166-1 alpha-2 -> alpha-3.
     * Gerada a partir do dataset iso-codes (iso_3166-1.json).
     */
    private const ALPHA3 = [
        'AD' => 'AND', 'AE' => 'ARE', 'AF' => 'AFG', 'AG' => 'ATG',
        'AI' => 'AIA', 'AL' => 'ALB', 'AM' => 'ARM', 'AO' => 'AGO',
        'AQ' => 'ATA', 'AR' => 'ARG', 'AS' => 'ASM', 'AT' => 'AUT',
        'AU' => 'AUS', 'AW' => 'ABW', 'AX' => 'ALA', 'AZ' => 'AZE',
        'BA' => 'BIH', 'BB' => 'BRB', 'BD' => 'BGD', 'BE' => 'BEL',
        'BF' => 'BFA', 'BG' => 'BGR', 'BH' => 'BHR', 'BI' => 'BDI',
        'BJ' => 'BEN', 'BL' => 'BLM', 'BM' => 'BMU', 'BN' => 'BRN',
        'BO' => 'BOL', 'BQ' => 'BES', 'BR' => 'BRA', 'BS' => 'BHS',
        'BT' => 'BTN', 'BV' => 'BVT', 'BW' => 'BWA', 'BY' => 'BLR',
        'BZ' => 'BLZ', 'CA' => 'CAN', 'CC' => 'CCK', 'CD' => 'COD',
        'CF' => 'CAF', 'CG' => 'COG', 'CH' => 'CHE', 'CI' => 'CIV',
        'CK' => 'COK', 'CL' => 'CHL', 'CM' => 'CMR', 'CN' => 'CHN',
        'CO' => 'COL', 'CR' => 'CRI', 'CU' => 'CUB', 'CV' => 'CPV',
        'CW' => 'CUW', 'CX' => 'CXR', 'CY' => 'CYP', 'CZ' => 'CZE',
        'DE' => 'DEU', 'DJ' => 'DJI', 'DK' => 'DNK', 'DM' => 'DMA',
        'DO' => 'DOM', 'DZ' => 'DZA', 'EC' => 'ECU', 'EE' => 'EST',
        'EG' => 'EGY', 'EH' => 'ESH', 'ER' => 'ERI', 'ES' => 'ESP',
        'ET' => 'ETH', 'FI' => 'FIN', 'FJ' => 'FJI', 'FK' => 'FLK',
        'FM' => 'FSM', 'FO' => 'FRO', 'FR' => 'FRA', 'GA' => 'GAB',
        'GB' => 'GBR', 'GD' => 'GRD', 'GE' => 'GEO', 'GF' => 'GUF',
        'GG' => 'GGY', 'GH' => 'GHA', 'GI' => 'GIB', 'GL' => 'GRL',
        'GM' => 'GMB', 'GN' => 'GIN', 'GP' => 'GLP', 'GQ' => 'GNQ',
        'GR' => 'GRC', 'GS' => 'SGS', 'GT' => 'GTM', 'GU' => 'GUM',
        'GW' => 'GNB', 'GY' => 'GUY', 'HK' => 'HKG', 'HM' => 'HMD',
        'HN' => 'HND', 'HR' => 'HRV', 'HT' => 'HTI', 'HU' => 'HUN',
        'ID' => 'IDN', 'IE' => 'IRL', 'IL' => 'ISR', 'IM' => 'IMN',
        'IN' => 'IND', 'IO' => 'IOT', 'IQ' => 'IRQ', 'IR' => 'IRN',
        'IS' => 'ISL', 'IT' => 'ITA', 'JE' => 'JEY', 'JM' => 'JAM',
        'JO' => 'JOR', 'JP' => 'JPN', 'KE' => 'KEN', 'KG' => 'KGZ',
        'KH' => 'KHM', 'KI' => 'KIR', 'KM' => 'COM', 'KN' => 'KNA',
        'KP' => 'PRK', 'KR' => 'KOR', 'KW' => 'KWT', 'KY' => 'CYM',
        'KZ' => 'KAZ', 'LA' => 'LAO', 'LB' => 'LBN', 'LC' => 'LCA',
        'LI' => 'LIE', 'LK' => 'LKA', 'LR' => 'LBR', 'LS' => 'LSO',
        'LT' => 'LTU', 'LU' => 'LUX', 'LV' => 'LVA', 'LY' => 'LBY',
        'MA' => 'MAR', 'MC' => 'MCO', 'MD' => 'MDA', 'ME' => 'MNE',
        'MF' => 'MAF', 'MG' => 'MDG', 'MH' => 'MHL', 'MK' => 'MKD',
        'ML' => 'MLI', 'MM' => 'MMR', 'MN' => 'MNG', 'MO' => 'MAC',
        'MP' => 'MNP', 'MQ' => 'MTQ', 'MR' => 'MRT', 'MS' => 'MSR',
        'MT' => 'MLT', 'MU' => 'MUS', 'MV' => 'MDV', 'MW' => 'MWI',
        'MX' => 'MEX', 'MY' => 'MYS', 'MZ' => 'MOZ', 'NA' => 'NAM',
        'NC' => 'NCL', 'NE' => 'NER', 'NF' => 'NFK', 'NG' => 'NGA',
        'NI' => 'NIC', 'NL' => 'NLD', 'NO' => 'NOR', 'NP' => 'NPL',
        'NR' => 'NRU', 'NU' => 'NIU', 'NZ' => 'NZL', 'OM' => 'OMN',
        'PA' => 'PAN', 'PE' => 'PER', 'PF' => 'PYF', 'PG' => 'PNG',
        'PH' => 'PHL', 'PK' => 'PAK', 'PL' => 'POL', 'PM' => 'SPM',
        'PN' => 'PCN', 'PR' => 'PRI', 'PS' => 'PSE', 'PT' => 'PRT',
        'PW' => 'PLW', 'PY' => 'PRY', 'QA' => 'QAT', 'RE' => 'REU',
        'RO' => 'ROU', 'RS' => 'SRB', 'RU' => 'RUS', 'RW' => 'RWA',
        'SA' => 'SAU', 'SB' => 'SLB', 'SC' => 'SYC', 'SD' => 'SDN',
        'SE' => 'SWE', 'SG' => 'SGP', 'SH' => 'SHN', 'SI' => 'SVN',
        'SJ' => 'SJM', 'SK' => 'SVK', 'SL' => 'SLE', 'SM' => 'SMR',
        'SN' => 'SEN', 'SO' => 'SOM', 'SR' => 'SUR', 'SS' => 'SSD',
        'ST' => 'STP', 'SV' => 'SLV', 'SX' => 'SXM', 'SY' => 'SYR',
        'SZ' => 'SWZ', 'TC' => 'TCA', 'TD' => 'TCD', 'TF' => 'ATF',
        'TG' => 'TGO', 'TH' => 'THA', 'TJ' => 'TJK', 'TK' => 'TKL',
        'TL' => 'TLS', 'TM' => 'TKM', 'TN' => 'TUN', 'TO' => 'TON',
        'TR' => 'TUR', 'TT' => 'TTO', 'TV' => 'TUV', 'TW' => 'TWN',
        'TZ' => 'TZA', 'UA' => 'UKR', 'UG' => 'UGA', 'UM' => 'UMI',
        'US' => 'USA', 'UY' => 'URY', 'UZ' => 'UZB', 'VA' => 'VAT',
        'VC' => 'VCT', 'VE' => 'VEN', 'VG' => 'VGB', 'VI' => 'VIR',
        'VN' => 'VNM', 'VU' => 'VUT', 'WF' => 'WLF', 'WS' => 'WSM',
        'YE' => 'YEM', 'YT' => 'MYT', 'ZA' => 'ZAF', 'ZM' => 'ZMB',
        'ZW' => 'ZWE',
    ];

    /**
     * Alpha-2 e alpha-3 passam direto (uppercased); qualquer outra coisa
     * (nome por extenso, codigo invalido) lanca excecao.
     */
    public static function toAlpha3(string $country): string
    {
        $code = strtoupper($country);

        if (strlen($code) === 3) {
            // Validar aqui e o espelho do que toAlpha2 ja fazia nos dois formatos.
            // Sem isto, 'BRZ' passava direto e o typo so aparecia como 400
            // generico do provider, longe de quem escreveu o typo.
            if (isset(self::alpha2()[$code])) {
                return $code;
            }

            throw new InvalidRequestException(sprintf(
                'O codigo de pais "%s" nao e um codigo ISO 3166-1 alpha-3 valido.',
                $country,
            ));
        }

        if (isset(self::ALPHA3[$code])) {
            return self::ALPHA3[$code];
        }

        throw new InvalidRequestException(sprintf(
            'O codigo de pais "%s" nao e um codigo ISO 3166-1 alpha-2 valido.',
            $country,
        ));
    }

    /**
     * Alpha-3 e alpha-2 passam direto (uppercased); qualquer outra coisa
     * (nome por extenso, codigo invalido) lanca excecao.
     */
    public static function toAlpha2(string $country): string
    {
        $code = strtoupper($country);

        if (strlen($code) === 2) {
            if (isset(self::ALPHA3[$code])) {
                return $code;
            }

            throw new InvalidRequestException(sprintf(
                'O codigo de pais "%s" nao e um codigo ISO 3166-1 alpha-2 valido.',
                $country,
            ));
        }

        if (isset(self::alpha2()[$code])) {
            return self::alpha2()[$code];
        }

        throw new InvalidRequestException(sprintf(
            'O codigo de pais "%s" nao e um codigo ISO 3166-1 alpha-3 valido.',
            $country,
        ));
    }

    /**
     * Derivada da mesma tabela ALPHA3 (nunca mantida a mao separadamente).
     *
     * @return array<string, string>
     */
    private static function alpha2(): array
    {
        static $lookup = null;

        return $lookup ??= array_flip(self::ALPHA3);
    }
}
