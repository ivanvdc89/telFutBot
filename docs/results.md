# D'on surten els resultats

`getResults.php` carrega a `team_results` els resultats d'una jornada. Ja no cal
buscar-los i enganxar-los al fitxer: es descarreguen d'una web pública.

## La font: kassiesa.net

<https://kassiesa.net/uefa/data/method5/match2027.html>

És la base de dades de partits de Bert Kassies, la referència de facto dels
coeficients UEFA des de fa dècades. És una pàgina HTML, no una API, però és la
que encaixa millor amb este joc:

- **Les tres competicions en un sol fitxer**, separades per una capçalera
  (`CHAMPIONS LEAGUE`, `EUROPA LEAGUE`, `CONFERENCE LEAGUE`).
- **Cada partit porta els dos equips, el país i els dos resultats** de
  l'eliminatòria (anada i tornada) en dues columnes.
- **Les jornades hi són explícites.** La fase de lliga són blocs separats per
  una fila buida (el bloc 1 és la jornada 1), i cada eliminatòria té la seua
  capçalera (`Knockout round play-offs`, `Round of 16`, `Quarter Finals`...).
  Això dona un mapa 1:1 amb la numeració de jornades del joc.
- **Els noms dels equips són els anglesos habituals**, que és exactament el que
  el joc ja feia servir quan s'enganxaven a mà (`Sparta Praha`, `Omonia
  Nicosia`, `FC Salzburg`).
- No cal cap clau ni cap registre, i no té límit de peticions.

### Per què no les altres

- **Wikipedia** també és HTML i té API, però cada ronda és una taula diferent,
  amb plantilles de banderes i noms que canvien (`A.C. Milan`, `Paris
  Saint-Germain F.C.`). El mapa ronda → jornada s'hauria de reconstruir a mà i
  el lector seria més fràgil. No aporta res que kassiesa no doni.
- **APIs obertes** (`football-data.org`, `API-Football`, `TheSportsDB`): donen
  JSON i dates, però la capa gratuïta no cobreix les tres competicions alhora,
  tenen límit de peticions, demanen clau i —el més important— el joc hauria de
  traduir igualment els seus noms d'equip. Per a una lliga privada que llegeix
  resultats un cop per jornada, no compensa la dependència.
- **APIs no documentades** (SofaScore, FotMob...): canvien sense avís i juguen
  contra els termes d'ús del lloc.

Si algun dia kassiesa canvia o desapareix, la resta del joc no se n'assabenta:
tot el que depèn de la font és darrere la interfície `ResultsSource`
(`sources/ResultsSource.php`) i de la implementació `KassiesaResultsSource`. Es
pot canviar de font tocant només `getResults.php`.

## Com es mapen jornada i ronda

| Jornada | Ronda de kassiesa |
| --- | --- |
| 1-8 | `League Stage` de la CHL i l'EUL, bloc 1-8 |
| 2-7 | `League Stage` de la COL, bloc 1-6 |
| 1 i 8 | (jornades simulades de la COL: tots els seus equips hi tenen 0) |
| 9 | (fi de la fase de lliga, sense partits; la tanca `calculateEndLeaguePhasePoints.php`) |
| 10-11 | `Knockout round play-offs`, anada i tornada |
| 12-13 | `Round of 16` |
| 14-15 | `Quarter Finals` |
| 16-17 | `Semi Finals` |
| 18 | `Final` (partit únic) |
| 19 | (fi del joc; el tanca `calculateEndGamePoints.php`) |

El mapa és a `config/results.php` (`RESULTS_LEAGUE_PHASE` i
`RESULTS_KNOCKOUT_ROUNDS`). Si la UEFA canvia el format, és l'únic lloc que s'ha
de tocar.

### La Conference només juga 6 jornades de lligueta

El joc en té 8 per a tothom, perquè les tres competicions avancin juntes. La
lligueta de la Conference ocupa les jornades **2 a 7**: els resultats de la seua
primera ronda es desen a la jornada 2 del joc, i la 1 i la 8 són **jornades
simulades**, en què tots els seus equips tenen un 0.

A més, com que juga dues jornades menys, **a la lligueta de la Conference la
victòria val 4 punts** en comptes de 3 (l'empat continua valent 1 i la derrota 0).
Amb 6 partits, guanyar-los tots dona 24 punts, els mateixos que guanyar els 8 de
la Champions o l'Europa League. A les eliminatòries la victòria torna a valer 3 a
totes les competicions.

I com que a les jornades 1 i 8 no s'hi juga res, **no s'hi poden activar accions
de competició per a la COL**: el bot no ofereix el botó de la Conference i, si
s'hi arriba escrivint la comanda, la rebutja dient per què. Si en quedés alguna
fila d'abans de la restricció, `calculateMatchDayPoints.php` la ignora, de manera
que no pot donar ni treure punts d'una jornada simulada.

## Com s'executa

```
php getResults.php                 # jornada en curs (config/matchDay.php)
php getResults.php 3               # una jornada concreta
php getResults.php 3 --dry-run     # ensenya què faria, sense escriure
php getResults.php --refresh       # torna a descarregar la pàgina
php getResults.php 10 --competition=CHL,EUL
php getResults.php --source=files/cache/kassiesa-2027.html
```

La pàgina es desa a `files/cache/` i es reutilitza durant una hora, aixina que
executar l'script diverses vegades no castiga el lloc. Si la xarxa falla, s'usa
la còpia local encara que sigui antiga.

`team_results` té una clau única `(team_id, match_day, competition)` i
**l'escriptura és només un `INSERT`**: els resultats no s'actualitzen mai. Si la
jornada ja té files, `getResults.php` les detecta abans d'escriure res, diu quins
equips ja hi són i s'atura. Per tornar a carregar una jornada cal esborrar-ne
primer les files (l'error mateix indica l'SQL):

```sql
DELETE FROM team_results WHERE match_day = 3;
```

## Què s'hi desa

Una fila per equip, jornada i competició, amb el resultat del partit:

| Columna | Què és |
| --- | --- |
| `points` | Els punts del partit: 3/1/0, i 4 per victòria a la lligueta de la Conference. |
| `goals_for` | Els gols que va marcar l'equip. |
| `goals_against` | Els gols que va rebre. |
| `goal_difference` | `goals_for` − `goals_against`. Es desa calculada perquè la classificació la pugui sumar directament, però no és una dada nova: la calcula el model a partir de les altres dues, aixina que no pot quedar descompensada amb el resultat. |

Els gols no reparteixen punts al joc. Són la matèria primera de la
**classificació d'equips**: sumant per jornades se sap qui va primer de cada
competició (punts) i qui té millor diferència de gols, que és el que desempata
els equips que queden igualats — per exemple, per decidir quin equip queda KO
quan hi ha empat de vots a `#kosAmbEscuts`.

Els gols a favor i en contra es guarden **en cru** i no només la diferència:
si algun dia cal l'ordre complet de la UEFA (diferència de gols → gols a favor →
...), la dada ja hi és i no cal tornar a baixar els resultats.

### Si la taula ja existia

`database/team_results.sql` és l'esquema sencer (fa `DROP TABLE`), i per a una
base de dades que ja existeix les columnes s'hi afegeixen amb:

```sql
ALTER TABLE team_results
    ADD COLUMN goals_for INT NOT NULL DEFAULT 0 AFTER points,
    ADD COLUMN goals_against INT NOT NULL DEFAULT 0 AFTER goals_for,
    ADD COLUMN goal_difference INT NOT NULL DEFAULT 0 AFTER goals_against;
```

Les files que ja hi eren es queden amb tots els gols a 0, que no és el seu
resultat de debò. Per omplir-les, mira quines jornades tens carregades:

```sql
SELECT match_day, COUNT(*) FROM team_results GROUP BY match_day;
```

i torna-les a carregar, esborrant-les primer. El resultat és el mateix (la font no
ha canviat), i este cop sí que s'hi desen els gols:

```sql
DELETE FROM team_results WHERE match_day IN (1, 2);
```
```
php getResults.php 1
php getResults.php 2
```

No cal recalcular els punts dels jugadors: els punts dels partits són els
mateixos, només s'hi afegeixen les columnes noves.

Si t'oblides de l'`ALTER`, `getResults.php` no escriu res i ho diu: comprova que
les columnes hi siguin abans de fer res.

## La classificació d'equips

`calculateTeamStandings.php` suma `team_results` i desa la classificació a
`team_standings`, una taula **derivada**: no s'hi escriu mai a mà i es pot
esborrar i tornar a calcular sempre que calgui.

```
php calculateTeamStandings.php
```

- **Només compten les 8 primeres jornades** (`STANDINGS_LAST_MATCH_DAY`, a
  `config/standings.php`). Les eliminatòries no hi entren, i la jornada 9 tampoc:
  és la del premi de final de fase (`calculateEndLeaguePhasePoints.php`).
- **Els punts són els de la competició** (3/1/0), no els del joc. A la lligueta de
  la Conference el joc dona 4 punts per victòria, perquè juga 6 jornades i no 8;
  a la competició en valen 3, i la classificació ha de ser la de la competició.
  Per això no se suma `team_results.points` sinó que es torna a calcular el
  resultat de cada partit. Les jornades simulades de la Conference (0-0 amb 0
  punts) no compten com un empat.
- **L'ordre** és punts, diferència de gols, gols a favor, pot (el cap de sèrie) i
  nom. Els tres primers són els criteris de la UEFA; el pot i el nom són el
  desempat final, perquè dos equips absolutament idèntics quedin sempre en el
  mateix ordre.
- **La posició** (`ranking`) es desa calculada i és dins de cada competició: 1 és
  el primer de la seua. Amb això, els desempats per vots de `#dobleORes` i
  `#kosAmbEscuts` (quin equip queda KO quan hi ha els mateixos vots, quin és el
  segon més votat...) es resolen amb una sola consulta, que és per a què existeix
  la taula.

Per crear-la (a una base de dades que encara no la té) i omplir-la:

```
mysql -u usuari -p base_de_dades < database/team_standings.sql
php calculateTeamStandings.php
```

## Els estats exclosos

Hi ha estats els equips dels quals **no participen al joc i no hi han de ser
mai**. Ara mateix, Israel. La llista és a `RESULTS_EXCLUDED_COUNTRIES`
(`config/results.php`) i es comprova pel **codi de país del partit**, no pel nom:
qualsevol equip israelià que aparegui en el futur (Maccabi Tel-Aviv, Maccabi
Haifa...) queda exclòs tot sol.

D'un partit on juga un equip exclòs **només es desa el rival**: els seus punts
només depenen del resultat (3/1/0), aixina que es calculen igual. El partit no
atura res ni surt com a error; a l'informe hi surt marcat amb `EXCLÒS`. Si els
dos equips són exclosos, no es desa cap fila.

## Quan un equip no es reconeix

La font i la base de dades escriuen els noms diferent: `Olympique Lyon` és
`Lyon`, `Omonia Nicosia` és `Omonia` i `PSV Eindhoven` és `PSV`.
`sources/TeamNameResolver.php` ho resol sol en la immensa majoria de casos
(trossos de nom, prefixos de club, ciutats que es poden treure, accents i
transliteracions).

Quan no s'hi atreveix, **no endevina**: imprimeix el nom de la font, el resultat
i els candidats, i no desa res (tret que es passi `--force`). Normalment n'hi ha
prou d'afegir una línia a `config/teamAliases.php`:

```php
'redstarbelgrade' => 'CrvenaZvezda',
'ofiheraklion'    => 'OFICrete',
```

La clau és el nom de la font normalitzat (minúscules, sense accents ni espais) i
el valor, el nom exacte de la taula `teams`. Després, torna a executar
`getResults.php`.

## Ordre dels passos de la jornada

```
getResults -> calculateTeamStandings -> applySubstitutions -> calculateMatchDayPoints
                                                           -> publishSubstitutions
                                                           -> publishResults
```

`getResults.php` ha d'anar **abans** de `calculateMatchDayPoints.php`: este
llegeix `team_results` per repartir els punts. `calculateTeamStandings.php` també
va després de `getResults.php`, perquè només llegeix `team_results`.

El termini de la jornada —tancar els canvis, aplicar-los, anunciar-los i obrir
les accions— és a [termini-jornada.md](termini-jornada.md).
