# El termini de la jornada

Cada jornada té dos moments en què l'administrador toca el joc:

- **El termini** (diumenge): es tanquen els canvis, s'apliquen, s'anuncien i
  s'obren les accions de competició. És el que explica este document.
- **El tancament** (després dels partits): es carreguen els resultats, es
  calculen els punts i es publica la classificació. Això és a
  [results.md](results.md) i a l'ordre que hi ha a `config/matchDay.php`.

Tot el que es toca són **quatre valors de `config/matchDay.php`** i **dos
scripts**. No hi ha cap llista escrita a mà: el menú `/accions` surt dels
interruptors, i tancar una cosa la treu de la llista tota sola.

## Els quatre valors

| Valor | Qui el mou | Què decideix |
| --- | --- | --- |
| `CURRENT_MATCH_DAY` | l'administrador, en canviar de jornada | La jornada en curs. La fan servir tots els scripts de la jornada i el bot. |
| `LAST_MATCH_DAY_WITH_CHANGES` | fix (15) | La regla de les semifinals: a partir de la jornada següent, els canvis estan tancats per norma, passi el que passi amb l'interruptor. |
| `SUBSTITUTIONS_OPEN` | l'administrador | Es poden fer canvis d'equips (`/out`, `/in`). |
| `ACTIONS_OPEN` | l'administrador | Es poden activar les accions de competició (`/malDia`, `/socElMillor`, `/guanyarOMorir`). |

Per passar de jornada només s'edita el número de `CURRENT_MATCH_DAY`, i **no es
toca fins que els canvis de la jornada estan aplicats** (vegeu el pas 2).

## El cicle d'una jornada

| Moment | Interruptors | Què passa |
| --- | --- | --- |
| Durant la setmana | `SUBSTITUTIONS_OPEN = true`, `ACTIONS_OPEN = false` | Els jugadors fan els canvis. Al menú `/accions` només hi ha `/substitució`. |
| Diumenge (el termini) | es tanquen els canvis i s'obren les accions | Els quatre passos d'este document. |
| Després dels partits | igual, amb les accions obertes | Resultats, punts i publicació. Al final: `CURRENT_MATCH_DAY + 1`, `SUBSTITUTIONS_OPEN = true`, `ACTIONS_OPEN = false`. |

Mentre els canvis estan oberts, cada jugador pot tenir **un canvi per competició
i jornada** (fins a tres pendents alhora: CHL, EUL i COL). Cada canvi costa **6
punts**, i és **gratis** per als jugadors que estan a més de 29 punts del líder;
el cost es calcula quan es registra el canvi i queda desat a la seua fila.
`/substitució remove` els esborra tots de cop: esborrar-ne un de sol podria
deixar els altres en una combinació il·legal de països, i per això no existeix.

## Els passos del diumenge

Tot això es fa **a la consola del servidor**, dins la carpeta del projecte. Els
scripts de la jornada són només de CLI: si es demanen per URL responen 403.

### 0. Comprovar d'on es parteix

`CURRENT_MATCH_DAY` ha de ser la jornada dels canvis que s'apliquen (per
exemple, la 2). Si es vol vore què hi ha pendent abans de tocar res:

```sql
SELECT id, player_id, competition, old_team_id, new_team_id, points_cost
FROM substitutions WHERE match_day = 2 AND pending = 1;
```

Els interruptors són en un fitxer versionat i el desplegament és un `git pull`
(`config/secrets.php`). El que mana és la còpia que serveix el webhook: si els
canvies al repositori, cal fer el `git pull` al servidor perquè el bot els vegi,
i no els has d'editar també a mà al servidor, o el pròxim `pull` xocarà.

### 1. Tancar els canvis

A `config/matchDay.php`:

```php
define('SUBSTITUTIONS_OPEN', false);
```

A partir d'aquí el bot ja no deixa fer canvis: `/out` i `/in` responen "Els
canvis estan tancats: el termini d'esta jornada s'ha acabat." i `/accions`
deixa d'oferir `/substitució`.

`/substitució` encara es pot demanar i encara mostra els canvis pendents, i
`/substitució remove` encara els esborra. És a propòsit: després del termini un
jugador pot retirar-se'n, però no pot afegir-ne cap de nou.

### 2. Aplicar els canvis

```
php applySubstitutions.php
```

Llegeix les substitucions amb `pending = 1` de la jornada en curs i:

- mou els equips a `player_teams` (o omple un pot buit, quan `old_team_id` és 0),
- marca cada fila com a executada (`pending = 0`),
- tot dins d'una sola transacció.

Imprimeix cada canvi (`- CHL (jugador 7): Milan -> Chelsea`) i **no envia res a
Telegram**: d'això se n'encarrega el pas 3. És idempotent: si no hi ha res
pendent diu "Cap substitució pendent per a la jornada N" i acaba bé. Si una
actualització falla, desfà la transacció sencera i surt amb codi 1: no queda mai
mig aplicat.

> **No canviïs `CURRENT_MATCH_DAY` abans d'executar este pas.** L'script busca
> les substitucions pendents de la jornada en curs; amb un altre número no en
> trobaria cap i les deixaria sense aplicar.

### 3. Publicar els canvis

```
php publishSubstitutions.php
```

Envia al grup "Canvis realitzats:", amb el nom del jugador, l'equip que deixa,
el que entra i el cost de cada canvi. Només llegeix el que ja està aplicat.

Es pot tornar a executar sempre que calgui: si Telegram falla, es repeteix
este pas i prou, sense tornar a aplicar res. Té una conseqüència que val la
pena saber: **reenvia la llista sencera** de canvis aplicats de la jornada, no
només els que faltaven anunciar.

### 4. Obrir les accions

A `config/matchDay.php`:

```php
define('ACTIONS_OPEN', true);
```

Ara `/accions` ofereix `/malDia`, `/socElMillor` i `/guanyarOMorir`. Les altres
comandes d'acció (`/dobleORes`, `/kos`, `/segurQuePasse`, `/millorFinal`) no
surten al menú, però també depenen d'este interruptor: amb les accions
tancades responen "No disponible".

S'obre **després d'aplicar i publicar** els canvis, no abans: ningú no ha de
triar una acció sobre una plantilla que encara ha de canviar.

### Resum

```php
// config/matchDay.php
SUBSTITUTIONS_OPEN = false;
```
```
php applySubstitutions.php
php publishSubstitutions.php
```
```php
// config/matchDay.php
ACTIONS_OPEN = true;
```

Els quatre passos, tots amb la mateixa `CURRENT_MATCH_DAY`. El número de jornada
es passa quan la jornada està tancada del tot (punts calculats i publicats), no
aquí.

## Com ho veu el jugador

| Comanda | Amb els canvis oberts | Amb el termini tancat | Amb les accions obertes |
| --- | --- | --- | --- |
| `/accions` | `/substitució` | "Ara mateix no hi ha cap acció disponible." i treu el teclat vell | `/malDia`, `/socElMillor`, `/guanyarOMorir` |
| `/substitució` | Estat dels pendents i menú d'equips | Mateix estat, més el motiu del tancament; només `remove` | Igual |
| `/out`, `/in` | Es guarda el canvi | El motiu del tancament, sense tocar res | Igual |
| `/malDia`, `/socElMillor`, `/guanyarOMorir` | "No disponible" | "No disponible" | Menú d'activar i desactivar |

Quan una d'estes tres accions ja té fila a la base de dades, el commutador
només bloqueja la modificació: l'estat es mostra sempre, amb les accions obertes
o tancades.

Els dos motius de tancament són diferents i els missatges també: el termini de la
jornada ("el termini d'esta jornada s'ha acabat") i la norma de les
semifinals ("no es poden fer canvis a partir de les semifinals").

## La resta de la jornada

Quan s'han jugat els partits, i sempre amb la jornada encara sense canviar:

```
php getResults.php                 # carrega els resultats de la jornada
php calculateTeamStandings.php     # recalcula la classificació d'equips
php calculateMatchDayPoints.php    # reparteix els punts
php publishResults.php             # classificació, punts i millors
php publishActions.php             # resum de les accions de la jornada
```

`getResults.php` ha d'anar abans de `calculateMatchDayPoints.php`, i els canvis
del pas 2 també: `calculateMatchDayPoints.php` llegeix les substitucions de la
jornada i en descompta el cost, i llegeix la plantilla per repartir els punts.
De fet, si quedara alguna fila pendent (`pending = 1`), l'script **s'atura i no
calcula res**: no ha de cobrar el cost d'un canvi que no s'ha aplicat. Primer
`applySubstitutions.php`, i si alguna substitució no s'ha de fer, el jugador la
pot traure amb `/substitució remove`.

`calculateMatchDayPoints.php` **no es pot passar dues vegades per la mateixa
jornada**: els punts són acumulatius i els sumaria un altre cop. Si la jornada ja
té punts calculats, l'script s'atura i no toca res. Per refer-la de debò (per
exemple, després de corregir una acció mal posada) cal demanar-ho explícitament,
i aleshores esborra les files d'eixa jornada abans de tornar-les a calcular:

```
php calculateMatchDayPoints.php 2 --recalculate
```

`calculateTeamStandings.php` també va després de `getResults.php`, perquè només
llegeix `team_results`. Es pot executar quan convingui: reescriu la taula sencera
i no toca res més.

`publishActions.php` només llegeix i envia el resum de les accions de la jornada:
no escriu res a la base de dades, aixina que es pot executar quan convingui.

## Si alguna cosa falla

- **`applySubstitutions.php` falla.** No s'ha aplicat res (la transacció s'ha
  desfet) i les substitucions segueixen pendents: no es perd res. Normalment el
  motiu és que el jugador ja no té l'equip que deixa anar; es corregeix i es
  torna a executar.
- **Telegram falla a `publishSubstitutions.php`.** Torna a executar el mateix
  publicador. No cal tornar a aplicar els canvis.
- **`calculateMatchDayPoints.php` s'atura dient que hi ha substitucions pendents.**
  Vol dir que `applySubstitutions.php` no s'ha executat (o que alguna fila ha
  quedat enrere). Aplica-les i torna-ho a provar; no s'ha calculat res.
- **`calculateMatchDayPoints.php` s'atura dient que la jornada ja té punts.** És
  la guarda que evita sumar-la dos vegades. Si de debò s'ha de refer (una acció
  mal posada, un resultat carregat malament), es torna a executar amb
  `--recalculate`, que esborra els punts d'eixa jornada i els recalcula. Si va
  quedar a mitges, `--recalculate` també ho arregla.
- **Un jugador s'ha de fer enrere després d'aplicar.** Si la fila encara és
  pendent, `/substitució remove` la treu. Si ja està aplicada (`pending = 0`),
  el canvi és a `player_teams`: per desfer-lo abans que es jugui la jornada,
  torna a obrir `SUBSTITUTIONS_OPEN`, deixa que faci el canvi nou i torna a
  executar el pas 2 i el pas 3 (l'anunci repetirà la llista sencera).
- **Semifinals.** A partir de la jornada següent a `LAST_MATCH_DAY_WITH_CHANGES`
  els canvis estan tancats per norma, amb l'interruptor obert o tancat. Si la
  numeració de les eliminatòries canvia, este valor és el primer a ajustar.

## Com comprovar-ho

Els tests no toquen la base de dades ni Telegram:

```
php tests/actionsMenuTest.php           # els quatre estats dels interruptors
php tests/substitutionActionTest.php    # el termini tancat i els pendents
php tests/matchDayPointsTest.php        # els punts de la jornada, sense BD
```

I a la base de dades, després del pas 2, cap fila de la jornada no ha de quedar
pendent:

```sql
SELECT id, player_id, competition, old_team_id, new_team_id, points_cost
FROM substitutions WHERE match_day = 2 AND pending = 0 ORDER BY id;
```
