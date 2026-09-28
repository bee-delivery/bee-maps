# bee-maps

SDK Laravel multi-provider de geolocalização. Google Maps e HERE atrás dos mesmos contratos, com respostas tipadas — trocar de provedor não muda o código que consome.

> **Status:** `0.x`. Os seis contratos da Fase 1 estão implementados nos dois provedores: autocomplete, geocoding, busca de lugares, rotas, matriz de rotas e otimização de paradas. A API pública pode mudar antes do `1.0.0`.

## Requisitos

- PHP >= 8.3
- Laravel 10, 11 ou 12

## Instalação

```bash
composer require ifha/bee-maps
php artisan vendor:publish --tag=bee-maps-config
```

```dotenv
GOOGLE_MAPS_KEY=
HERE_API_KEY=
```

## Uso

O provedor é **sempre explícito**. O pacote nunca decide por você, não cacheia e não guarda estado entre chamadas.

```php
use BeeDelivery\BeeMaps\MapServiceFactory;
use BeeDelivery\BeeMaps\Enums\Provider;
use BeeDelivery\BeeMaps\DTOs\Requests\AutocompleteRequest;

final class BuscaDeEndereco
{
    public function __construct(private MapServiceFactory $maps) {}

    public function buscar(string $texto): void
    {
        $sugestoes = $this->maps->autocomplete(Provider::Here)
            ->suggest(new AutocompleteRequest($texto, countries: ['BR']));

        foreach ($sugestoes as $sugestao) {
            echo $sugestao->mainText;
        }
    }
}
```

A `MapServiceFactory` é injetável. Há também uma facade, para uso pontual:

```php
use BeeDelivery\BeeMaps\Facades\BeeMaps;

$sugestoes = BeeMaps::autocomplete(Provider::Google)->suggest($request);
```

### Escolhendo o provedor em tempo de execução

O pacote não conhece a sua regra de negócio. Se a escolha depende de algum dado seu — cliente, região, teste A/B —, resolva no seu código e passe o `Provider` já escolhido:

```php
$provider = $this->flags->providerPara($contaId, Service::Autocomplete);

$this->maps->autocomplete($provider)->suggest($request);
```

## Autocomplete

```php
new AutocompleteRequest(
    query:        'Av Paulista 1000',
    near:         new Coordinates(-23.5615, -46.6562),  // opcional
    radiusMeters: 3000,                                  // default 50000
    countries:    ['BR'],                                // default: região do config
    language:     'pt-BR',                               // default: idioma do config
);
```

Cada `Suggestion` traz:

| Campo | Tipo | |
|---|---|---|
| `place` | `?PlaceReference` | **pode ser nulo** — ver abaixo |
| `description` | `string` | endereço completo |
| `mainText` | `string` | linha principal — rua e número, ou o nome do lugar |
| `secondaryText` | `string` | complemento (bairro, cidade, UF, CEP) |
| `isEstablishment` | `bool` | é estabelecimento, não endereço |
| `coordinates` | `?Coordinates` | **pode ser nulo** — só o `/autosuggest` do HERE preenche; [ver abaixo](#coordinates-chega-de-graça-só-em-um-dos-três-caminhos) |

### O HERE tem dois endpoints de autocomplete, e a escolha depende do `near`

O Google resolve autocomplete com um endpoint só. O HERE tem dois, e nenhum faz o que o outro faz:

| | `/autosuggest` | `/autocomplete` |
|---|---|---|
| Devolve POI | sim | **não** — só endereço e área administrativa |
| Busca sem foco espacial | **não** — 400 sem `at`/`in=circle`/`in=bbox`/`in=ring` | sim |
| `in=countryCode` sozinho | recusado | aceito |
| `isEstablishment` | reflete o resultado | sempre `false` |
| `place` nulo | acontece (`chainQuery`) | nunca |
| `coordinates` | vem na resposta | sempre nulo |

A restrição que separa os dois está no spec do GS7: em `/autosuggest` (e em `/discover`) o `in=countryCode` *"must be accompanied by exactly one of `at`, `in=circle` or `in=bbox`"*. Ou seja, **só o `/autocomplete` consegue buscar no país inteiro** — o caso de quem cadastra um endereço em outro estado e não tem ponto de referência nenhum.

Por isso o default é `autocomplete_strategy = 'auto'`, que escolhe pela requisição:

```php
// tem referência (a loja, o cliente) → /autosuggest, com POI
$maps->autocomplete(Provider::Here)
    ->suggest(new AutocompleteRequest('Av Paulista', new Coordinates(-23.5615, -46.6562)));

// não tem referência → /autocomplete, Brasil inteiro
$maps->autocomplete(Provider::Here)
    ->suggest(new AutocompleteRequest('Rua Blumenau'));
```

Para fixar um dos dois, veja [`autocomplete_strategy`](#estratégia-de-autocomplete-do-here). O `bee-maps.here.autosuggest_center` **não influencia o modo `auto`**: se influenciasse, um centro esquecido no config viraria um recorte de 50 km em volta dele numa busca que pediu alcance nacional.

### `place` pode ser nulo — verifique antes do `lookup()`

O Autosuggest do HERE devolve também itens `chainQuery` e `categoryQuery` (ex.: "Postos Shell"), que são **refinamentos de busca, não lugares**, e não podem ser resolvidos. No Google todo resultado tem identificador, então lá o campo nunca é nulo. No `/autocomplete` do HERE o campo também nunca é nulo.

```php
$sugestao = $sugestoes->first();

if ($sugestao->place !== null) {
    $endereco = $this->maps->geocoding(Provider::Here)->lookup($sugestao->place);
}
```

### `coordinates` chega de graça só em um dos três caminhos

Depois que o usuário escolhe uma sugestão, quem vai mostrar o pino no mapa ou gravar o endereço precisa de latitude e longitude — e a forma óbvia de obtê-las é uma chamada de `lookup()` ou `geocode()`. O campo `coordinates` existe para evitar essa segunda chamada **quando o provider já mandou a posição na própria resposta do autocomplete**. Nem sempre manda:

| caminho | `coordinates` |
|---|---|
| HERE `/autosuggest` (busca **com** `near`) | vem preenchido, exceto nos itens `chainQuery`/`categoryQuery` |
| HERE `/autocomplete` (busca **sem** `near`) | sempre nulo |
| Google `places:autocomplete` | sempre nulo |

As duas ausências são do provider, não do pacote, e não têm parâmetro que as resolva:

- A doc do `/autocomplete` do HERE **instrui** a resolver a posição depois, via `/lookup` pelo `id` ou via `/geocode` pelo endereço. A tabela de *response enrichment* do GS7 não tem nenhum valor de `show` que adicione `position` a esse endpoint — `tz`, por exemplo, vale em *"all except `/autocomplete`"*.
- No Google, o `placePrediction` não tem campo de localização nenhum: são só `place`, `placeId`, `text`, `structuredFormat`, `types` e `distanceMeters`. A coordenada só sai do Place Details, que é outra chamada e outro SKU — então ampliar o field mask não mudaria nada.

Por isso o contrato é **"pode ser nulo"**, nunca "vem preenchido": o fallback continua obrigatório, inclusive para quem hoje só usa o HERE com `near` e um dia trocar de provider.

```php
$sugestao = $sugestoes->first();

$coordenadas = $sugestao->coordinates
    ?? $this->maps->geocoding($provider)->lookup($sugestao->place)?->coordinates;
```

Nos itens em que `coordinates` é nulo por serem refinamentos de busca, `place` **também** é nulo — os dois campos caem juntos, e nesse caso não há o que resolver.

## Geocoding

```php
$geocoding = $this->maps->geocoding(Provider::Google);

$geocoding->geocode('Av Paulista 1000', new GeocodeFilters(city: 'São Paulo'));
$geocoding->reverse(new Coordinates(-23.5615, -46.6562));
$geocoding->lookup($placeReference);   // devolve ?GeocodeResult
```

Cada `GeocodeResult` traz `address` (`Address`), `coordinates`, `partial`, `matchScore` e `place`.

### Identificadores de lugar não atravessam provedores

Um `place_id` do Google não significa nada para o HERE. `PlaceReference` registra quem o emitiu, e `lookup()` recusa referências de outro provedor em vez de responder "não encontrado":

```php
$doGoogle = new PlaceReference(Provider::Google, 'ChIJ...');

$maps->geocoding(Provider::Here)->lookup($doGoogle);
// PlaceReferenceProviderMismatchException
```

Se você grava identificadores no banco, **grave também o provedor** — ou re-geocodifique por texto ao trocar.

## Busca de lugares

```php
use BeeDelivery\BeeMaps\DTOs\Requests\PlaceSearchRequest;

$lugares = BeeMaps::placeSearch(Provider::Google)->search(
    new PlaceSearchRequest('farmacia', new Coordinates(-23.5615, -46.6562)),
);

foreach ($lugares as $lugar) {
    echo $lugar->name, ' — ', $lugar->address->formatted, PHP_EOL;
}
```

Cada `Place` traz `place` (`?PlaceReference`), `name`, `address` (`Address` estruturado nos dois provedores) e `coordinates`.

**O HERE exige contexto geográfico.** O `discover` não aceita busca sem `at` ou `in`: passe `near`, ou `region`, ou deixe `bee-maps.defaults.region` preenchido. Sem nenhum dos três, o pacote lança `InvalidRequestException` **antes** de sair para a rede, em vez de deixar o HERE responder 400. No Google os três são opcionais.

## Rota

```php
use BeeDelivery\BeeMaps\DTOs\Requests\RouteRequest;
use BeeDelivery\BeeMaps\Enums\TravelMode;

$rota = BeeMaps::routing(Provider::Here)->route(new RouteRequest(
    origin: new Coordinates(-23.5615, -46.6562),
    destination: new Coordinates(-23.5505, -46.6425),
    intermediates: [new Coordinates(-23.5580, -46.6500)],
    mode: TravelMode::TwoWheeler,
    optimizeIntermediates: true,
    includePolyline: true,
    includeLegs: true,
));

echo $rota->distance->kilometers(), ' km em ', $rota->duration->minutes(), ' min', PHP_EOL;

// raw() para repassar ao front; coordinates() decodifica sob demanda.
$pontos = $rota->polyline?->coordinates() ?? $rota->legs[0]->polyline->coordinates();
```

`polyline`, `legs` e `optimizedOrder` são **opt-in**: sem `includePolyline`, `includeLegs` e `optimizeIntermediates`, os dois provedores devolvem `null`, `[]` e `[]`. Cada um deles custa: no Google o `computeRoutes` é cobrado pelos campos do field mask, e no HERE o traçado volta como uma string longa por trecho. Por isso nada disso vem sem você pedir.

### Rotas alternativas

`alternatives` pede rotas adicionais para o mesmo trajeto, de 0 a 6. O padrão é 0 — uma rota só, como antes.

```php
$rota = BeeMaps::routing($provider)->route(new RouteRequest(
    origin: new Coordinates(-23.5615, -46.6562),
    destination: new Coordinates(-23.5505, -46.6333),
    alternatives: 3,
));

// A rota devolvida é a preferida do provedor; as outras vêm em `alternatives`.
$maisCurta = min(array_map(
    fn (Route $r) => $r->distance->meters,
    [$rota, ...$rota->alternatives],
));
```

**O pacote não escolhe entre elas.** "Mais curta" ou "mais rápida" é regra de quem chama, e as duas respostas são legítimas: a preferida é a que o motorista provavelmente fará, a mais curta é a de menor distância cobrada. Escolher aqui dentro seria decidir política de cobrança pelo consumidor.

É **um pedido, não uma garantia**, e a assimetria é do upstream, não do pacote:

| | parâmetro | quantidade |
|---|---|---|
| Google | `computeAlternativeRoutes: true` | a critério dele — o número que você passou é ignorado |
| HERE | `alternatives=N` | honra o `N`, devolve até `N+1` rotas |

O teto de 6 vem do HERE (`alternatives=7` responde 400) e é validado no `RouteRequest`, para que o mesmo valor seja recusado do mesmo jeito nos dois provedores, antes de qualquer chamada. Custa **uma** chamada upstream, não N. Alternativas não aninham alternativas.

`TravelMode` tem quatro casos, traduzidos para o vocabulário de cada API:

| `TravelMode` | Google | HERE |
|---|---|---|
| `Drive` | `DRIVE` | `car` |
| `TwoWheeler` | `TWO_WHEELER` | `scooter` |
| `Bicycle` | `BICYCLE` | `bicycle` |
| `Walk` | `WALK` | `pedestrian` |

## Matriz de rotas

```php
use BeeDelivery\BeeMaps\DTOs\Requests\RouteMatrixRequest;

$matriz = BeeMaps::routeMatrix(Provider::Google)->matrix(new RouteMatrixRequest(
    origins: [$origemA, $origemB],
    destinations: [$destinoA, $destinoB],
));

// Busque por par — nunca por posição: o Google devolve os elementos fora de ordem.
$entrada = $matriz->entry(originIndex: 0, destinationIndex: 1);

if ($entrada->reachable) {
    echo $entrada->distance->kilometers(), ' km', PHP_EOL;
}
```

## Otimização de paradas

Este contrato responde **em que ordem visitar as paradas** — não o traçado. Para geometria, use [Rota](#rota).

```php
use BeeDelivery\BeeMaps\DTOs\Requests\OptimizeWaypointsRequest;
use BeeDelivery\BeeMaps\Enums\OptimizationObjective;

$resultado = BeeMaps::routeOptimization(Provider::Here)->optimize(
    new OptimizeWaypointsRequest($origem, $destino, $paradas),
);

// $order indexa $paradas, e é sempre permutação completa dela.
$reordenadas = array_map(fn (int $i) => $paradas[$i], $resultado->order);

echo $resultado->distance->kilometers(), ' km em ', $resultado->duration->minutes(), ' min', PHP_EOL;
```

### Os três formatos de tour saem do campo `destination`

```php
// Fim fixo: sai da origem, passa pelas paradas, termina em $destino.
new OptimizeWaypointsRequest($origem, $destino, $paradas);

// Volta ao ponto de partida: basta o destino ser a origem.
new OptimizeWaypointsRequest($origem, $origem, $paradas);

// Tour aberto: termina na ultima parada que a otimizacao escolher.
new OptimizeWaypointsRequest($origem, null, $paradas);
```

**`$order` nunca contém origem nem destino** — são pontos fixos, não resultado da otimização. E nunca vem parcial: uma ordem com buraco apagaria paradas no `array_map` acima, então índice repetido, fora de faixa ou contagem errada viram `ProviderRequestException`.

### Objetivo e estratégia

O chamador pede **o quê**; o provedor decide **como** e informa o caminho em `strategy`:

```php
new OptimizeWaypointsRequest($origem, $destino, $paradas, objective: OptimizationObjective::MinDistance);

$resultado->strategy;   // 'google.matrix_tsp'
```

| `strategy` | Quando | O que usa |
|---|---|---|
| `google.routes` | `MinTravelTime` | `computeRoutes` com `optimizeWaypointOrder` |
| `google.matrix_tsp` | `MinDistance` (default) | matriz de rotas + TSP local (vizinho-mais-próximo + 2-opt) |
| `google.fleet_routing` | `MinDistance`, se configurado | Cloud Fleet Routing (`optimizeTours`) |
| `here.findsequence` | sempre | `/v8/findsequence2` com `improveFor` |

`MinTravelTime` é o default. Qual API atende o `MinDistance` no Google sai de:

```dotenv
BEE_MAPS_GOOGLE_MIN_DISTANCE_API=matrix_tsp   # ou fleet_routing
```

Um valor desconhecido cai no default em vez de derrubar a chamada — um typo no `.env` não deve tirar a otimização do ar.

### `fleet_routing` exige dependência e credencial a mais

A Cloud Fleet Routing é o único endpoint do pacote que não aceita chave de API: precisa de OAuth de service account. Por isso o `google/apiclient` é **sugerido, não exigido** — ele arrasta `google/auth`, `firebase/php-jwt` e Guzzle, e a estratégia default não usa nada disso.

```bash
composer require google/apiclient
```

```dotenv
BEE_MAPS_GOOGLE_MIN_DISTANCE_API=fleet_routing
BEE_MAPS_GOOGLE_PROJECT_ID=
BEE_MAPS_GOOGLE_RO_PRIVATE_KEY=
BEE_MAPS_GOOGLE_RO_CLIENT_EMAIL=
```

Pedir `fleet_routing` sem o pacote instalado lança `ConfigurationException` dizendo o comando **e** que o default `matrix_tsp` não precisa dele. Credencial incompleta lança `MissingCredentialsException` nomeando a chave que falta.

## Códigos de país

O pacote aceita ISO 3166-1 **alpha-2** (`BR`) ou **alpha-3** (`BRA`) e converte para o formato que cada API exige — o Google quer alpha-2, o HERE quer alpha-3. Um código inválido lança `InvalidRequestException` em vez de produzir um filtro que a API ignora em silêncio.

```php
new AutocompleteRequest('Av Paulista', countries: ['BR']);   // ok
new AutocompleteRequest('Av Paulista', countries: ['BRA']);  // ok
new AutocompleteRequest('Av Paulista', countries: ['XX']);   // InvalidRequestException
```

## `partial` e `matchScore`

Os dois provedores respondem perguntas diferentes sobre a qualidade do resultado:

- **Google** devolve `partial_match`, um booleano: "não casei tudo que você pediu".
- **HERE** devolve `scoring.queryScore`, um número de 0 a 1: "casei *assim* de bem".

`GeocodeResult` carrega os dois. `matchScore` é o número cru (`null` no Google) e `partial` é derivado dele pelo limiar `bee-maps.here.partial_threshold`.

> **O limiar default `1.0` não está calibrado.** É o valor mais conservador possível: marca como parcial até um resultado com score 0.97, que na prática é um endereço ótimo. Consequência: o HERE reporta `partial = true` com muito mais frequência que o Google.
>
> Se o seu código ramifica nesse campo, **calibre o limiar contra endereços reais antes de trocar de provedor** — rode o mesmo conjunto nos dois e compare a distribuição do `queryScore` com o `partial_match`. Quando precisar de precisão, leia o `matchScore` diretamente.

```dotenv
BEE_MAPS_HERE_PARTIAL_THRESHOLD=1.0
```

## Erros

| Exceção | Quando |
|---|---|
| `ProviderNotSupportedException` | provedor fora de `bee-maps.providers` |
| `ServiceNotSupportedByProviderException` | provedor não implementa aquele serviço |
| `MissingCredentialsException` | chave de API ausente no config |
| `InvalidRequestException` | entrada inválida (coordenada fora de faixa, código de país desconhecido) |
| `PlaceReferenceProviderMismatchException` | referência de lugar de outro provedor |
| `ProviderAuthenticationException` | 401 / 403 |
| `ProviderRateLimitException` | 429 |
| `ProviderUnavailableException` | 5xx ou timeout |

Todas descendem de `BeeMapsException`, e as três últimas expõem `provider()`, `service()`, `httpStatus()` e `providerCode()`.

**Nenhum resultado não é erro.** Buscas devolvem coleção vazia; `lookup()` devolve `null`. Quem decide se isso é um problema é o seu caso de uso.

**Credenciais não vazam para o log.** Mensagens de erro de conexão do cURL incluem a URL completa, e o HERE envia a chave na query string. O pacote redige esses valores (`apiKey=[REDACTED]`) antes de construir a exceção, e não encadeia a exceção original — que carregaria a mensagem crua para o seu rastreador de erros.

## Observabilidade

Cada chamada dispara `MapRequestCompleted`:

```php
use BeeDelivery\BeeMaps\Support\Events\MapRequestCompleted;

Event::listen(MapRequestCompleted::class, function (MapRequestCompleted $e) {
    Log::info('maps', [
        'provider' => $e->provider->value,
        'service'  => $e->service->value,
        'status'   => $e->httpStatus,      // 0 quando houve falha de conexão
        'ms'       => $e->durationMs,
        'chamadas' => $e->upstreamCalls,
    ]);
});
```

O evento dispara **também em falha e em timeout** — sem isso, um provedor que estoura tempo em 30% das chamadas seria indistinguível de um que nunca foi chamado.

## Configuração

```php
'defaults' => [
    'language' => env('BEE_MAPS_LANGUAGE', 'pt-BR'),
    'region'   => env('BEE_MAPS_REGION', 'BR'),   // usada quando countries vem vazio
],

'http' => [
    'timeout'         => 10,
    'connect_timeout' => 3,
    'attempts'        => 2,    // TOTAL de tentativas, incluindo a primeira
    'retry_delay_ms'  => 200,
],
```

Só 429 e 5xx são repetidos. Falhas de autenticação (401/403) **não** são — repeti-las dobraria a carga exatamente quando o provedor está bloqueando você.

Todo endpoint é sobrescrevível por quem publica o config, o que permite apontar para um proxy ou corrigir uma URL sem esperar release do pacote:

```php
'google' => ['endpoints' => [
    'autocomplete' => 'https://places.googleapis.com/v1/places:autocomplete',
    'geocoding'    => 'https://maps.googleapis.com/maps/api/geocode/json',
    'place_search' => 'https://places.googleapis.com/v1/places:searchText',
    'routing'      => 'https://routes.googleapis.com/directions/v2:computeRoutes',
]],

'here' => ['endpoints' => [
    'autosuggest'  => 'https://autosuggest.search.hereapi.com/v1/autosuggest',
    'autocomplete' => 'https://autocomplete.search.hereapi.com/v1/autocomplete',
    'geocode'      => 'https://geocode.search.hereapi.com/v1/geocode',
    'revgeocode'   => 'https://revgeocode.search.hereapi.com/v1/revgeocode',
    'lookup'       => 'https://lookup.search.hereapi.com/v1/lookup',
    'discover'     => 'https://discover.search.hereapi.com/v1/discover',
    'routing'      => 'https://router.hereapi.com/v8/routes',
    'findsequence' => 'https://wps.hereapi.com/v8/findsequence2',
]],
```

### Estratégia de autocomplete do HERE

Qual dos dois endpoints atende `autocomplete()` (ver [o porquê de serem dois](#o-here-tem-dois-endpoints-de-autocomplete-e-a-escolha-depende-do-near)):

```dotenv
BEE_MAPS_HERE_AUTOCOMPLETE_STRATEGY=auto
```

| Valor | Comportamento |
|---|---|
| `auto` (default) | `/autosuggest` quando a requisição traz `near`, `/autocomplete` quando não traz |
| `autosuggest` | sempre `/autosuggest`. Tem POI, mas exige foco espacial |
| `autocomplete` | sempre `/autocomplete`. Cobre o país inteiro, mas `isEstablishment` é sempre `false` |

O HERE tem ainda uma chave que não é endpoint: o **foco espacial de fallback do Autosuggest**, usado quando `AutocompleteRequest::$near` vem vazio. Ele só tem efeito na estratégia `autosuggest` — em `auto`, uma busca sem `near` vai para o `/autocomplete` e não é recortada por ele. Na estratégia `autosuggest`, sem esta chave e sem `near`, o pacote lança `InvalidRequestException` (ver [Limitações conhecidas](#limitações-conhecidas)). O formato é o mesmo de `Coordinates::toString()`:

```dotenv
BEE_MAPS_HERE_AUTOSUGGEST_CENTER="-23.5615,-46.6562"
```

```php
'here' => [
    'autocomplete_strategy' => env('BEE_MAPS_HERE_AUTOCOMPLETE_STRATEGY', 'auto'),
    // "latitude,longitude", ou null para exigir `near` na estratégia autosuggest.
    'autosuggest_center' => env('BEE_MAPS_HERE_AUTOSUGGEST_CENTER'),
],
```

Um valor malformado em qualquer uma das duas é `ConfigurationException`, não `InvalidRequestException`: quem precisa agir é quem fez o deploy, não quem fez a chamada.

## Serviços disponíveis

| Serviço | Google | HERE |
|---|---|---|
| Autocomplete | `places:autocomplete` | `/v1/autosuggest` ou `/v1/autocomplete`, conforme a [estratégia](#estratégia-de-autocomplete-do-here) |
| Geocoding | Geocoding API | `/v1/geocode`, `/v1/revgeocode`, `/v1/lookup` |
| PlaceSearch | `places:searchText` | `/v1/discover` |
| Routing | `directions/v2:computeRoutes` | `/v8/routes` (+ `/v8/findsequence2` quando otimiza) |
| RouteMatrix | `distanceMatrix/v2:computeRouteMatrix` | `/v8/matrix?async=false` |
| RouteOptimization | `computeRoutes`, `computeRouteMatrix` + TSP local, ou `optimizeTours` | `/v8/findsequence2` |

## Limitações conhecidas

**A normalização de endereços é ajustada ao Brasil.** Revise antes de usar fora do país:

- O hífen do CEP é removido (`01310-100` → `01310100`). No Brasil ele é cosmético, mas em Portugal, Polônia e Japão faz parte do código.
- No Google, `administrative_area_level_2` é mapeado para `city`. No Brasil esse nível é o município; nos Estados Unidos é o *county*, e o HERE devolveria a cidade — os dois provedores discordariam.

**No HERE, busca nacional e POI são mutuamente exclusivos.** O `/autosuggest` devolve POI mas recusa a chamada sem um de `at`/`in=bbox`/`in=circle`/`in=ring` — filtro de país não conta como foco. O `/autocomplete` aceita `in=countryCode` sozinho mas não tem `resultType` de lugar, então `isEstablishment` é sempre `false` ali. O modo `auto` alterna entre os dois pela presença de `near`, mas não existe combinação que dê as duas coisas: uma busca sem ponto de referência no HERE não vai encontrar estabelecimento pelo nome. O Google entrega POI nos dois casos, então esta é uma diferença real de provider, não do pacote.

**Na estratégia `autosuggest` forçada, o autocomplete do HERE exige foco espacial.** Informe `AutocompleteRequest::$near` ou configure `bee-maps.here.autosuggest_center`; sem nenhum dos dois, o pacote lança `InvalidRequestException` em vez de deixar o HTTP 400 vazar.

**O autocomplete do HERE pede `show=details` no Autosuggest.** Sem esse parâmetro o `address` da resposta vem só com `label`, e não há como separar `mainText` de `secondaryText`: o `title` do Autosuggest vem **igual ao endereço completo** em todo resultado que não é um lugar nomeado, então usá-lo colapsaria a linha inteira em `mainText`. Isso importa além da estética — quem conta vírgulas em `mainText` para saber se falta o número da casa (é o caso do entregame) aceitaria uma rua sem número. A doc do HERE avisa que `show` pode envolver chamadas adicionais e aumentar a latência; é o preço dos dois campos corretos.

**A coordenada do autocomplete só existe no `/autosuggest` do HERE.** O `Suggestion::$coordinates` poupa uma chamada de geocode depois da escolha do usuário, mas só no caminho com `near`. O `/autocomplete` do HERE e o `places:autocomplete` do Google não devolvem posição, e não há parâmetro nem field mask que mude isso — ver [a seção do campo](#coordinates-chega-de-graça-só-em-um-dos-três-caminhos). Quem depender da coordenada precisa manter o fallback por `lookup()`/`geocode()`.

**Uma resposta malformada do HERE devolve coleção vazia** em vez de erro de mapeamento, pela mesma regra de "nenhum resultado não é erro".

**`Route::polyline` é nulo no HERE quando a rota tem waypoints intermediários.** O HERE devolve uma polyline por trecho e não existe forma válida de concatenar duas *flexible polylines* como string. Nesse caso a geometria vive em `RouteLeg::polyline`. O Google sempre devolve a polyline da rota inteira.

**`PlaceSearch` no Google usa o SKU Enterprise do Text Search.** O field mask pede `places.addressComponents` para que `Place::address` venha estruturado como no HERE. Quem preferir o SKU Basic remove o campo do `GooglePlaceSearchRequestMapper::fieldMask()` e passa a receber `Address` apenas com `formatted`.

**Rota otimizada no HERE custa duas chamadas upstream.** O `/v8/routes` não reordena waypoints; a ordem vem da Waypoints Sequence API (`/v8/findsequence2`). As duas chamadas são agrupadas em um único `MapRequestCompleted` com `upstreamCalls: 2`, para que a comparação de latência contra o Google não atribua ao HERE uma lentidão sem causa visível. O endpoint fica em `bee-maps.here.endpoints.findsequence` porque a documentação do HERE é ambígua entre `findsequence2` e `findsequence.json`.

**Sobre a matriz de rotas:**

- **O Google limita a matriz a 625 elementos** (origens × destinos); o pacote lança
  `MatrixTooLargeException` antes da chamada, com o tamanho pedido e o limite. O HERE
  síncrono aguenta muito mais (25.000 elementos verificados), então uma matriz que
  funciona no HERE pode ser recusada no Google — divida em lotes se precisar de paridade.
- **Pares sem rota vêm com `reachable = false` e medidas zeradas**, nunca ausentes. Uma
  matriz com buracos é mais difícil de consumir do que uma completa.
- **Só o modo síncrono.** O modo assíncrono do HERE (submit → poll → download) depende de
  job e não cabe dentro de uma request HTTP.
- **Matriz incompleta é erro, não resultado parcial.** Se o provider interromper o cálculo
  no meio (o `computeRouteMatrix` do Google faz isso com HTTP 200, anexando o erro ao
  final do stream) ou responder sem a matriz, o pacote lança `ProviderRequestException`
  em vez de devolver uma coleção com buracos.

**Sobre a otimização de paradas:**

- **O TSP local é vizinho-mais-próximo com refino 2-opt, e a rota devolvida nunca é mais
  longa que a ordem que você mandou.** A `google.matrix_tsp` continua sendo heurística —
  devolve uma boa rota, não a ótima —, mas a rota do guloso passa por 2-opt e é comparada
  com a ordem de entrada: vence a menor, e o empate mantém a sua ordem intacta. Sem essa
  comparação a "otimização" podia devolver rota mais longa que a rota sem otimização
  nenhuma, o que vira cobrança maior onde a taxa sai da distância (BEE-12720 no pacote
  legado, corrigido lá na 1.3.5). Held-Karp continua fora: o custo exponencial do exato
  não se paga com o teto de 25 paradas.
- **No HERE o `mode` é sempre `fastest`**, nunca `shortest`. O `shortest` muda como cada
  perna é roteada e não tem equivalente no `computeRouteMatrix` do Google — usá-lo faria a
  comparação entre provedores medir perguntas diferentes. O objetivo entra por `improveFor`.
- **Uma parada só não é erro.** A ordem é trivialmente `[0]`, mas os totais não são, e
  recusar quebraria quem itera sobre pedidos e às vezes encontra um de uma parada só.

**A suíte não roda contra Laravel 10.** O `orchestra/testbench ^8.0` só casa com versões pontuais do Laravel 10 bloqueadas por advisories de segurança. Isso afeta só o desenvolvimento do pacote — **consumidores em Laravel 10 instalam normalmente** (verificado por resolução do Composer com plataforma forçada).

## Atualizando o pacote

Se você **publicou** `config/bee-maps.php`, saiba que o pacote mescla o config dele com o
seu **em profundidade**: chaves novas (endpoints de serviços novos, limites) aparecem
automaticamente, e o que você definiu continua valendo. A única exceção são listas — como
`providers` —, que são substituídas inteiras pela sua versão, para que um item removido de
propósito não volte sozinho.

> **Se você usa `php artisan config:cache`, reconstrua o cache depois de atualizar o pacote.**
> Com a configuração cacheada o pacote não mescla nada — é o mesmo comportamento do
> `mergeConfigFrom` do Laravel, e existe porque nesse modo o `.env` não é carregado —, então
> chaves novas só aparecem depois de rodar `php artisan config:cache` de novo.

## Migrando do `beedelivery/google-maps`

Três mudanças observáveis, além dos tipos:

1. **Retornos são DTOs, não arrays.** `$resposta['addresses'][0]['lat']` vira `$resultado->coordinates->latitude`.
2. **Erro é exceção, não chave no array.** Some o `isset($response['code'])`; some também a exceção para "nenhum resultado", que agora é coleção vazia.
3. **O autocomplete do Google passa a ser restrito à região configurada** quando `countries` não é informado. O pacote antigo buscava no mundo todo. Foi o preço de `countries: []` significar a mesma coisa nos dois provedores — "irrestrito nos dois" é impossível, porque o Autosuggest do HERE exige `at` ou `in`. Passe `countries` explicitamente se quiser outro comportamento.

## Testes

```bash
composer install
vendor/bin/phpunit                  # suite completa, sem rede
vendor/bin/phpunit --group live     # smoke contra as APIs reais (exige chaves, custa dinheiro)
```

Nenhum teste da suíte padrão toca a rede — `Http::preventStrayRequests()` está ativo, então uma requisição não simulada falha em vez de sair.

Os testes `live` são a exceção e por isso ficam **excluídos por padrão** no `phpunit.xml`. Eles precisam de `GOOGLE_MAPS_KEY` e `HERE_API_KEY` no ambiente; sem elas, se marcam como skipped sem tentar a chamada. Servem para responder o que nenhum `Http::fake()` responde: o endpoint existe e a chave tem acesso a ele?

O gate de chave é **por provedor**: com só uma das duas no ambiente, o smoke daquele provedor roda e o do outro pula.

## Licença

MIT. Ver [LICENSE](LICENSE).
