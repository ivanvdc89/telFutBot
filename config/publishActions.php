<?php

require_once(__DIR__ . '/menus.php');

/**
 * Tot el que publishActions.php fa amb les accions d'una jornada, tret de la
 * base de dades i Telegram:
 *
 *   - actionsAnnouncement() llegeix el JSON que desa el bot i en treu l'anunci
 *     (els noms, els vots i els resums);
 *   - actionsPublishText() el converteix en el missatge que rep el grup.
 *
 * És aquí i no dins de l'script pel mateix motiu que el menú de /accions
 * (config/actions.php): el missatge és el que veuen els jugadors, i un recompte
 * equivocat o un text mal fet es noten de seguida. Sense base de dades ni
 * Telegram, tot això es pot provar solt a tests/publishActionsTest.php.
 *
 * LES REGLES DELS RECOMPTES (les de les normes de /normes accions)
 *   - #dobleORes: l'equip més votat de cada competició no suma cap punt i el
 *     segon suma doble.
 *   - #kosAmbEscuts: els KOS_BOICOT_TEAMS equips més votats no sumen punts,
 *     tret dels qui porten escut. Si ningú no vota però l'acció estava oberta,
 *     el boicot el decidixen els equips amb més punts a la classificació.
 *   - #kosPais i #kosPots: els KOS_BOICOT_GROUPS països o pots més votats, i
 *     tots els seus equips queden a 0 punts. Si ningú no vota, els grups amb
 *     més punts sumats. L'empat el decidix eixa suma.
 *   - #millorFinal: la final més votada dona +6 al guanyador, la segona +2, i
 *     la menys votada -2 al perdedor.
 *
 * ELS EMPATS
 *   - Als vots per equip, els empatats es resolen per la classificació
 *     d'equips (team_standings): el millor classificat va davant. Si la taula
 *     encara no té dades, l'empat manté l'ordre en què s'han votat per primera
 *     vegada, perquè les funcions d'ordenació són estables des de PHP 8.0.
 *   - A #dobleORes, si hi ha almenys un vot a l'acció, el segon pot ser un
 *     equip sense vots (els empats a 0 també es resolen per la classificació),
 *     i una competició sense cap vot reparteix per classificació: el líder
 *     queda a 0 i el segon dobla.
 *   - A #millorFinal els empats mantenen l'ordre CHL, EUL, COL, que és el que
 *     feia el publicador d'abans.
 */

/**
 * Ordre de les seccions del missatge, el mateix que el de /normes accions.
 */
const ACTION_PUBLISH_ORDER = [
    'badDay',
    'iAmTheBest',
    'winOrDie',
    'doubleOrNothing',
    'kosAndShields',
    'kosPais',
    'kosPots',
    'bestFinal',
    'sureToBeQualified',
];

/**
 * Etiqueta de cada tipus d'acció: la columna `type` de la taula actions.
 */
const ACTION_PUBLISH_LABELS = [
    'badDay'            => '#malDia',
    'iAmTheBest'        => '#socElMillor',
    'winOrDie'          => '#guanyarOMorir',
    'doubleOrNothing'   => '#dobleORes',
    'kosAndShields'     => '#kosAmbEscuts',
    'kosPais'           => '#kosPais',
    'kosPots'           => '#kosPots',
    'bestFinal'         => '#millorFinal',
    'sureToBeQualified' => '#segurQuePasse',
];

/**
 * Competicions del joc, en l'ordre en què s'expliquen.
 */
const ACTION_COMPETITIONS = ['CHL', 'EUL', 'COL'];

/**
 * Equips que queden a 0 punts per #kosAmbEscuts. La norma diu "els 4 més
 * votats", i és el mateix número que explica /normes accions. Si no hi ha cap
 * vot, són els 4 equips amb més punts a la classificació.
 */
const KOS_BOICOT_TEAMS = 4;

/**
 * Països o pots que queden boicotejats a #kosPais i #kosPots. La norma diu "els
 * 2 més votats", i és el mateix número que explica /normes accions.
 *
 * També és el nombre de grups que queden bloquejats si no hi ha cap vot: els de
 * més punts sumats entre tots els seus equips.
 */
const KOS_BOICOT_GROUPS = 2;

/**
 * Etiqueta d'un tipus d'acció.
 */
function actionLabel(string $type): string
{
    return ACTION_PUBLISH_LABELS[$type] ?? $type;
}

/**
 * '1 vot' o 'N vots'.
 */
function actionsVotesText(int $votes): string
{
    return $votes . ($votes === 1 ? ' vot' : ' vots');
}

/**
 * '1 punt' o 'N punts'.
 */
function actionsPointsText(int $points): string
{
    return $points . ($points === 1 ? ' punt' : ' punts');
}

/**
 * Noms units amb la conjunció: 'A', 'A i B', 'A, B i C'.
 *
 * @param array<int, string> $names
 */
function actionsJoinNames(array $names): string
{
    $names = array_values($names);

    if (count($names) === 0) {
        return '';
    }

    if (count($names) === 1) {
        return (string) $names[0];
    }

    $last = array_pop($names);

    return implode(', ', $names) . ' i ' . $last;
}

/**
 * Vots per equip, de més a menys.
 *
 * Un element de $teamIds és un vot. Els empatats mantenen l'ordre en què s'han
 * votat per primera vegada; el desempat definitiu el fa
 * actionsSortVotesByStandings(), que ja té la classificació d'equips.
 *
 * @param array<int, int> $teamIds
 *
 * @return array<int, int> [team_id => vots]
 */
function actionsVoteTotals(array $teamIds): array
{
    $totals = [];

    foreach ($teamIds as $teamId) {
        $totals[$teamId] = ($totals[$teamId] ?? 0) + 1;
    }

    arsort($totals);

    return $totals;
}

/**
 * Ordena els vots d'una competició: primer per vots (de més a menys) i, si hi
 * ha empat, per la posició a la classificació d'equips (team_standings).
 *
 * Si la classificació encara no té dades (abans de calcular-la), l'empat manté
 * l'ordre en què s'han votat per primera vegada: les funcions d'ordenació són
 * estables des de PHP 8.0.
 *
 * @param array<int, int> $votesByTeam   [team_id => vots]
 * @param array<int, int> $teamRankings  [team_id => posició], 1 és el primer
 *
 * @return array<int, int> [team_id => vots], ja ordenat
 */
function actionsSortVotesByStandings(array $votesByTeam, array $teamRankings): array
{
    uksort(
        $votesByTeam,
        static function ($teamA, $teamB) use ($votesByTeam, $teamRankings): int {
            $byVotes = $votesByTeam[$teamB] <=> $votesByTeam[$teamA];

            if ($byVotes !== 0) {
                return $byVotes;
            }

            $rankA = $teamRankings[$teamA] ?? PHP_INT_MAX;
            $rankB = $teamRankings[$teamB] ?? PHP_INT_MAX;

            return $rankA <=> $rankB;
        }
    );

    return $votesByTeam;
}

/**
 * Els equips més votats, en ordre, sense comptar els que no han rebut cap vot.
 *
 * L'empat es resol per la classificació d'equips, igual que a #dobleORes: el
 * que decidix quin equip entra al boicot quan hi ha els mateixos vots al tall.
 *
 * Si no hi ha cap vot, la norma de #kosAmbEscuts diu que queden boicotejats els
 * millors de la classificació: per això, quan es passa $teamPoints, es torna
 * els equips amb més punts. Sense punts (o sense vots i sense classificació)
 * torna una llista buida, com abans.
 *
 * @param array<int, int> $totals       [team_id => vots]
 * @param array<int, int> $teamRankings [team_id => posició], 1 és el primer
 * @param array<int, int> $teamPoints   [team_id => punts a la seua competició]
 *
 * @return array<int, int> ids d'equip
 */
function actionsMostVotedTeams(array $totals, int $howMany, array $teamRankings = [], array $teamPoints = []): array
{
    $teams = [];

    foreach (actionsSortVotesByStandings($totals, $teamRankings) as $teamId => $votes) {
        if (count($teams) >= $howMany) {
            break;
        }

        if ($votes > 0) {
            $teams[] = (int) $teamId;
        }
    }

    if (count($teams) === 0 && count($teamPoints) > 0) {
        return actionsBestTeamsByPoints($teamPoints, $teamRankings, $howMany);
    }

    return $teams;
}

/**
 * Els equips amb més punts a la classificació, en ordre.
 *
 * És el boicot de #kosAmbEscuts quan ningú no ha votat. Els punts són els de la
 * competició de cada equip (team_standings.points) i l'empat el decidix la
 * posició a la classificació; si encara empaten, l'id, perquè el resultat no
 * depenga de l'ordre en què la base de dades torna les files.
 *
 * @param array<int, int> $teamPoints   [team_id => punts a la seua competició]
 * @param array<int, int> $teamRankings [team_id => posició], 1 és el primer
 *
 * @return array<int, int> ids d'equip
 */
function actionsBestTeamsByPoints(array $teamPoints, array $teamRankings, int $howMany): array
{
    $teamIds = array_keys($teamPoints);

    usort(
        $teamIds,
        static function ($teamA, $teamB) use ($teamPoints, $teamRankings): int {
            $byPoints = $teamPoints[$teamB] <=> $teamPoints[$teamA];

            if ($byPoints !== 0) {
                return $byPoints;
            }

            $rankA = $teamRankings[$teamA] ?? PHP_INT_MAX;
            $rankB = $teamRankings[$teamB] ?? PHP_INT_MAX;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return $teamA <=> $teamB;
        }
    );

    return array_map('intval', array_slice($teamIds, 0, max(0, $howMany)));
}

/**
 * Els grups (països o pots) més votats, en ordre.
 *
 * Un vot és un element de $votes amb la clau del grup (el nom del país o el
 * número global del pot). L'empat el decidix la suma de punts dels equips del
 * grup (team_standings); si encara empaten, la clau, perquè el resultat no
 * depenga de l'ordre dels vots.
 *
 * Si no hi ha cap vot però es passen els punts, queden els grups amb més punts
 * sumats: és el que fa #kosPais i #kosPots quan ningú no vota. Sense vots ni
 * punts no hi ha cap grup.
 *
 * @param array<int, string|int> $votes       Un element per vot: país o pot
 * @param array<string, int>     $groupPoints [grup => suma de punts dels seus equips]
 *
 * @return array<int, string> les claus dels grups, de més a menys
 */
function actionsMostVotedGroups(array $votes, int $howMany, array $groupPoints = []): array
{
    $totals = [];

    foreach ($votes as $vote) {
        $group = (string) $vote;

        if ($group === '') {
            continue;
        }

        $totals[$group] = ($totals[$group] ?? 0) + 1;
    }

    if (count($totals) === 0) {
        return actionsBestGroupsByPoints($groupPoints, $howMany);
    }

    uksort(
        $totals,
        static function ($groupA, $groupB) use ($totals, $groupPoints): int {
            $byVotes = $totals[$groupB] <=> $totals[$groupA];

            if ($byVotes !== 0) {
                return $byVotes;
            }

            $byPoints = ($groupPoints[$groupB] ?? 0) <=> ($groupPoints[$groupA] ?? 0);

            if ($byPoints !== 0) {
                return $byPoints;
            }

            // Ordre natural: 'CHL 2' abans de 'CHL 10'.
            return strnatcmp((string) $groupA, (string) $groupB);
        }
    );

    // array_keys() convertix les claus numèriques ('1') en enters; es tornen a
    // text perquè el grup siga sempre una cadena, com el vot.
    return array_map('strval', array_slice(array_keys($totals), 0, max(0, $howMany)));
}

/**
 * Els grups amb més punts sumats, en ordre: el boicot sense vots.
 *
 * @param array<string, int> $groupPoints [grup => suma de punts dels seus equips]
 *
 * @return array<int, string> les claus dels grups, de més a menys
 */
function actionsBestGroupsByPoints(array $groupPoints, int $howMany): array
{
    $groups = array_keys($groupPoints);

    usort(
        $groups,
        static function ($groupA, $groupB) use ($groupPoints): int {
            $byPoints = $groupPoints[$groupB] <=> $groupPoints[$groupA];

            return $byPoints !== 0 ? $byPoints : strnatcmp((string) $groupA, (string) $groupB);
        }
    );

    return array_map('strval', array_slice($groups, 0, max(0, $howMany)));
}

/**
 * Els equips d'uns grups (països o pots), sense repetir.
 *
 * És el que convertix un boicot de "2 països" o "2 pots" en la llista d'equips
 * que queden a 0 punts.
 *
 * @param array<int, string>              $groups     Claus dels grups
 * @param array<string, array<int, int>>  $groupTeams [grup => ids d'equip]
 *
 * @return array<int, int> ids d'equip
 */
function actionsGroupTeams(array $groups, array $groupTeams): array
{
    $teamIds = [];

    foreach ($groups as $group) {
        foreach ($groupTeams[$group] ?? [] as $teamId) {
            $teamId = (int) $teamId;

            if (!in_array($teamId, $teamIds, true)) {
                $teamIds[] = $teamId;
            }
        }
    }

    return $teamIds;
}

/**
 * El resultat de #dobleORes: per competició, l'equip més votat i el segon.
 *
 * L'equip més votat de cada competició queda a 0 punts i el segon suma doble.
 * Si hi ha empat, mana la classificació d'equips. El segon pot ser un equip
 * sense vots, i si una competició no té cap vot tots els equips estan empatats
 * a 0: mana la classificació, o siga que el líder queda a 0 i el segon dobla.
 * Això passa quan hi ha almenys un vot a l'acció; si no n'hi ha cap, no es
 * reparteix res.
 *
 * Si no hi ha classificació d'equips (team_standings buit), no es poden ordenar
 * els equips sense vots: es manté el comportament antic i només compten els
 * equips que han rebut algun vot.
 *
 * @param array<int, int>    $totals           [team_id => vots]
 * @param array<int, string> $teamCompetitions [team_id => 'CHL'|'EUL'|'COL']
 * @param array<int, int>    $teamRankings     [team_id => posició], 1 és el primer
 *
 * @return array<string, array{top: array<int, int>, second: array<int, int>}>
 */
function actionsDoubleOrNothingResult(array $totals, array $teamCompetitions, array $teamRankings = []): array
{
    $result = [];
    foreach (ACTION_COMPETITIONS as $competition) {
        $result[$competition] = ['top' => [], 'second' => []];
    }

    // Sense cap vot a tota l'acció no es reparteix res. Si n'hi ha almenys un,
    // les competicions que no han rebut vots es resolen per la classificació.
    if (array_sum($totals) === 0) {
        return $result;
    }

    // Tots els equips de cada competició, amb els vots que han rebut (0 si no
    // n'han rebut cap): el segon es pot triar entre els que no han votat ningú.
    $byCompetition = [];
    foreach ($teamCompetitions as $teamId => $competition) {
        if (!isset($result[$competition])) {
            continue;
        }

        $byCompetition[$competition][(int) $teamId] = (int) ($totals[$teamId] ?? 0);
    }

    foreach ($byCompetition as $competition => $votesByTeam) {
        // Si la classificació no té cap d'estos equips, no es poden ordenar els
        // que no han rebut vots: només compten els votats, com abans.
        $hasRankings = false;
        foreach (array_keys($votesByTeam) as $teamId) {
            if (isset($teamRankings[$teamId])) {
                $hasRankings = true;

                break;
            }
        }

        if (!$hasRankings) {
            $votesByTeam = array_filter($votesByTeam, static fn(int $votes): bool => $votes > 0);
        }

        $teamIds = array_keys(actionsSortVotesByStandings($votesByTeam, $teamRankings));

        if (count($teamIds) > 0) {
            $result[$competition]['top'][] = (int) $teamIds[0];
        }

        if (count($teamIds) > 1) {
            $result[$competition]['second'][] = (int) $teamIds[1];
        }
    }

    return $result;
}

/**
 * El rànquing de les finals per vots (#millorFinal), de més a menys.
 *
 * @param array<string, int> $votesByCompetition
 *
 * @return array<string, int>
 */
function actionsBestFinalRanking(array $votesByCompetition): array
{
    $ranking = array_fill_keys(ACTION_COMPETITIONS, 0);

    foreach ($ranking as $competition => $votes) {
        $ranking[$competition] = (int) ($votesByCompetition[$competition] ?? 0);
    }

    arsort($ranking);

    return $ranking;
}

/**
 * Una línia per jugador i equip votat, amb els vots que hi ha posat.
 *
 * Els vots arriben un per element, perquè és com els desa el bot: aquí
 * s'agrupen per jugador i equip ("2 vots a Arsenal" en una sola línia).
 *
 * @param array<int, array{player: string, team: string}> $votes
 * @param string                                          $connector 'a' o 'de KO a'
 *
 * @return array<int, string>
 */
function actionsVoteLines(array $votes, string $connector): array
{
    $grouped = [];
    foreach ($votes as $vote) {
        $player = (string) $vote['player'];
        $team   = (string) $vote['team'];

        $grouped[$player][$team] = ($grouped[$player][$team] ?? 0) + 1;
    }

    $lines = [];
    foreach ($grouped as $player => $teams) {
        foreach ($teams as $team => $count) {
            $lines[] = '- ' . $player . ': ' . actionsVotesText($count) . ' ' . $connector . ' ' . $team;
        }
    }

    return $lines;
}

/**
 * Un subbloc del resum (el resultat dels vots, el boicot, el rànquing), amb una
 * línia en blanc al davant perquè respiri.
 *
 * @param array<int, string> $lines
 *
 * @return array<int, string>
 */
function actionsSubBlock(array $lines): array
{
    return count($lines) > 0 ? array_merge([''], $lines) : [];
}

/**
 * El text d'una secció, o cadena buida si aquell tipus no té res a dir.
 *
 * @param string $type Tipus d'acció (columna `type` de la taula actions).
 * @param array  $data Accions del tipus, amb els noms ja resolts.
 */
function actionsSectionText(string $type, array $data): string
{
    $lines = [];

    if ($type === 'doubleOrNothing') {
        $lines = actionsVoteLines($data['votes'] ?? [], 'a');
        $lines = array_merge($lines, actionsSubBlock(actionsDoubleOrNothingText($data['result'] ?? [], $data['totals'] ?? [])));
    } elseif ($type === 'kosAndShields') {
        $lines = actionsVoteLines($data['kos'] ?? [], 'de KO a');

        foreach ($data['shields'] ?? [] as $shield) {
            $lines[] = '- ' . $shield['player'] . ': 1 escut a ' . $shield['team'];
        }

        $lines = array_merge($lines, actionsSubBlock(actionsKosBoicotText($data['boicot'] ?? [], (bool) ($data['byStandings'] ?? false))));
    } elseif ($type === 'kosPais' || $type === 'kosPots') {
        $lines = actionsVoteLines($data['vots'] ?? [], 'a');

        foreach ($data['escuts'] ?? [] as $shield) {
            $lines[] = '- ' . $shield['player'] . ': 1 escut a ' . $shield['team'];
        }

        $noun = $type === 'kosPais' ? 'països' : 'pots';
        $lines = array_merge($lines, actionsSubBlock(
            actionsKosGroupBoicotText($data['boicot'] ?? [], $noun, (bool) ($data['byStandings'] ?? false))
        ));
    } elseif ($type === 'bestFinal') {
        foreach ($data['votes'] ?? [] as $vote) {
            $lines[] = '- ' . $vote['player'] . ': ' . actionsVotesText((int) $vote['votes'])
                . ' a ' . $vote['competition'];
        }

        $lines = array_merge($lines, actionsSubBlock(actionsBestFinalText($data['ranking'] ?? [])));
    } elseif ($type === 'sureToBeQualified') {
        foreach ($data as $entry) {
            $lines[] = '- ' . $entry['player'] . ': ' . $entry['team']
                . ' (' . actionsPointsText((int) $entry['points']) . ' en joc)';
        }
    } else {
        foreach ($data as $entry) {
            $lines[] = '- ' . $entry['player'] . ': ' . implode(', ', $entry['values']);
        }
    }

    if (count($lines) === 0) {
        return '';
    }

    return actionLabel($type) . "\n" . implode("\n", $lines);
}

/**
 * Un equip del resultat de #dobleORes, amb els vots que ha rebut si els sabem.
 *
 * @param array<int, string> $names  Noms de l'equip (normalment un)
 * @param array<string, int> $totals [nom => vots]
 * @param string             $effect 'sumarà 0 punts' o 'sumarà doble'
 */
function actionsDoubleOrNothingTeamText(array $names, array $totals, string $effect): string
{
    $name = actionsJoinNames($names);

    // Si l'empat no s'ha pogut resoldre (dades velles o fetes a mà), no hi
    // haurà un sol nom i el recompte per equip no es pot assignar.
    if (count($names) !== 1) {
        return $name . ' (' . $effect . ')';
    }

    $votes = $totals[$names[0]] ?? null;

    if ($votes === null) {
        return $name . ' (' . $effect . ')';
    }

    return $name . ' (' . actionsVotesText((int) $votes) . ', ' . $effect . ')';
}

/**
 * El resum de #dobleORes: qui queda a 0 punts i qui suma doble a cada
 * competició, amb els vots que ha rebut cada equip.
 *
 * @param array<string, array{top: array<int, string>, second: array<int, string>}> $result Noms, no ids.
 * @param array<string, int>                                                       $totals [nom => vots]
 *
 * @return array<int, string>
 */
function actionsDoubleOrNothingText(array $result, array $totals = []): array
{
    if (count($result) === 0) {
        return [];
    }

    $lines = [
        "Equips més votats (el més votat no sume cap punt i el segon sume doble):"
    ];

    foreach ($result as $competition => $teams) {
        $top    = $teams['top'] ?? [];
        $second = $teams['second'] ?? [];

        if (count($top) === 0) {
            $lines[] = '- ' . $competition . ': cap vot';

            continue;
        }

        $line = '- ' . $competition . ': ' . actionsDoubleOrNothingTeamText($top, $totals, 'sumarà 0 punts');
        $line .= count($second) > 0
            ? '; ' . actionsDoubleOrNothingTeamText($second, $totals, 'sumarà doble')
            : '; cap segon equip amb vots';

        $lines[] = $line;
    }

    return $lines;
}

/**
 * El resum de #kosAmbEscuts: els equips que queden a 0 punts, amb els escuts
 * que els salven.
 *
 * Quan ningú no ha votat, els equips no són "els més votats" sinó els millors
 * de la classificació, i la capçalera ho diu.
 *
 * @param array<int, array{team: string, shieldedBy: array<int, string>}> $boicot
 * @param bool $byStandings Cert si el boicot s'ha decidit per la classificació
 *
 * @return array<int, string>
 */
function actionsKosBoicotText(array $boicot, bool $byStandings = false): array
{
    if (count($boicot) === 0) {
        return [];
    }

    $how = $byStandings ? ' millors de la classificació' : ' equips més votats';
    $lines = ['Els ' . min(KOS_BOICOT_TEAMS, count($boicot)) . $how . ' (no sumen punts):'];

    foreach ($boicot as $team) {
        $line = '- ' . $team['team'];

        if (count($team['shieldedBy'] ?? []) > 0) {
            $line .= ' (amb escut de ' . actionsJoinNames($team['shieldedBy']) . ', se salva)';
        }

        $lines[] = $line;
    }

    return $lines;
}

/**
 * El resum de #kosPais i #kosPots: els països o pots que queden a 0 punts.
 *
 * L'equip no hi ix perquè el boicot és del grup sencer: qui vulga saber quins
 * equips queden afectats els té a la llista de dalt (els vots) i a la de dalt
 * de tot (els escuts).
 *
 * @param array<int, array{grup: string}> $boicot
 * @param string $noun 'països' o 'pots'
 * @param bool   $byStandings Cert si el boicot s'ha decidit per la classificació
 *
 * @return array<int, string>
 */
function actionsKosGroupBoicotText(array $boicot, string $noun, bool $byStandings = false): array
{
    if (count($boicot) === 0) {
        return [];
    }

    $how   = $byStandings ? ' amb més punts' : ' més votats';
    $lines = ['Els ' . min(KOS_BOICOT_GROUPS, count($boicot)) . ' ' . $noun . $how . ' (no sumen punts):'];

    foreach ($boicot as $group) {
        $lines[] = '- ' . $group['grup'];
    }

    return $lines;
}

/**
 * El rànquing de #millorFinal, amb el que guanya i el que perd cada final.
 *
 * @param array<string, int> $ranking [competició => vots], de més a menys.
 *
 * @return array<int, string>
 */
function actionsBestFinalText(array $ranking): array
{
    if (count($ranking) === 0) {
        return [];
    }

    $lines  = ['Rànquing de finals:'];
    $effect = ['+6 al guanyador', '+2 al guanyador', '-2 al perdedor'];
    $index  = 0;

    foreach ($ranking as $competition => $votes) {
        $lines[] = '- ' . $competition . ': ' . actionsVotesText((int) $votes)
            . ' (' . ($effect[$index] ?? '') . ')';
        $index++;
    }

    return $lines;
}

/**
 * El nom d'un equip, o #id si no es coneix (una fila vella, un equip esborrat).
 *
 * @param array<int, string> $teamNames [team_id => nom]
 */
function actionsTeamName(array $teamNames, int $teamId): string
{
    return $teamNames[$teamId] ?? ('#' . $teamId);
}

/**
 * Converteix les files d'actions d'una jornada en el que cal per publicar-les.
 *
 * És tota la feina de publishActions.php que no és ni base de dades ni Telegram:
 * llegir el JSON de cada tipus d'acció (que és el contracte amb el bot), resoldre
 * els noms i calcular els resums de les votacions. El retorn és l'estructura que
 * espera actionsPublishText().
 *
 * @param array<int, array>  $actionRows       Files de getActionsByMatchDay().
 * @param array<int, int>    $allowedVotes     [player_id => vots], la regla de posició.
 * @param array<int, string> $playerNames      [player_id => nom].
 * @param array<int, string> $teamNames        [team_id => nom].
 * @param array<int, string> $teamCompetitions [team_id => 'CHL'|'EUL'|'COL']
 * @param int                $minVotes         Vots de la regla de posició per a les files velles
 * @param array<int, int>    $teamRankings     [team_id => posició] de team_standings, per desempatar
 * @param array<int, int>    $teamPoints       [team_id => punts] de team_standings
 * @param array<int, string> $teamCountries    [team_id => país] per al boicot de #kosPais
 * @param array<int, int>    $teamPots         [team_id => pot global] per al boicot de #kosPots
 */
function actionsAnnouncement(
    array $actionRows,
    array $allowedVotes,
    array $playerNames,
    array $teamNames,
    array $teamCompetitions,
    int $minVotes,
    array $teamRankings = [],
    array $teamPoints = [],
    array $teamCountries = [],
    array $teamPots = []
): array {
    $announcement = [
        'badDay'            => [],
        'iAmTheBest'        => [],
        'winOrDie'          => [],
        'sureToBeQualified' => [],
        'doubleOrNothing'   => ['votes' => [], 'result' => [], 'totals' => []],
        'kosAndShields'     => ['kos' => [], 'shields' => [], 'boicot' => [], 'byStandings' => false],
        'kosPais'           => ['vots' => [], 'escuts' => [], 'boicot' => [], 'byStandings' => false],
        'kosPots'           => ['vots' => [], 'escuts' => [], 'boicot' => [], 'byStandings' => false],
        'bestFinal'         => ['votes' => [], 'ranking' => []],
    ];

    $doubleOrNothingVoted = [];   // ids d'equip votats, un element per vot
    $kosVotes             = ['kosAndShields' => [], 'kosPais' => [], 'kosPots' => []];
    $kosShieldedBy        = [];   // [team_id => [noms dels qui l'han protegit]]
    $kosRowSeen           = ['kosAndShields' => false, 'kosPais' => false, 'kosPots' => false];
    $bestFinalVotes       = [];   // [competició => vots]

    foreach ($actionRows as $action) {
        $playerId = (int) $action['player_id'];
        $name     = $playerNames[$playerId] ?? ('#' . $playerId);
        $type     = (string) $action['type'];
        $data     = json_decode((string) $action['data'], true);
        $data     = is_array($data) ? $data : [];

        if ($type === 'doubleOrNothing') {
            foreach ($data['teams'] ?? [] as $teamId) {
                $teamId = (int) $teamId;

                $doubleOrNothingVoted[]                     = $teamId;
                $announcement['doubleOrNothing']['votes'][] = [
                    'player' => $name,
                    'team'   => actionsTeamName($teamNames, $teamId),
                ];
            }

            continue;
        }

        // Els tres boicots comparteixen la fila (vots + escuts), però cada un
        // guarda els vots en una clau pròpia: equips, països o pots.
        if (isset($kosVotes[$type])) {
            $kosRowSeen[$type] = true;
            $voteKey           = $type === 'kosAndShields' ? 'kos' : ($type === 'kosPais' ? 'paisos' : 'pots');
            $listKey           = $type === 'kosAndShields' ? 'kos' : 'vots';
            $shieldKey         = $type === 'kosAndShields' ? 'shields' : 'escuts';

            foreach ($data[$voteKey] ?? [] as $vote) {
                if ($type === 'kosPots') {
                    $vote    = (int) $vote;
                    $display = potMenuLabel(potLabel($vote));
                } elseif ($type === 'kosPais') {
                    $vote    = (string) $vote;
                    $display = $vote;
                } else {
                    $vote    = (int) $vote;
                    $display = actionsTeamName($teamNames, $vote);
                }

                $kosVotes[$type][] = $vote;

                $announcement[$type][$listKey][] = [
                    'player' => $name,
                    'team'   => $display,
                ];
            }

            foreach ($data['shields'] ?? [] as $teamId) {
                $teamId = (int) $teamId;

                if (!in_array($name, $kosShieldedBy[$teamId] ?? [], true)) {
                    $kosShieldedBy[$teamId][] = $name;
                }

                $announcement[$type][$shieldKey][] = [
                    'player' => $name,
                    'team'   => actionsTeamName($teamNames, $teamId),
                ];
            }

            continue;
        }

        if ($type === 'bestFinal') {
            $competition = $data['competition'] ?? ($data[0] ?? null);
            $votes       = (int) ($data['points'] ?? $allowedVotes[$playerId] ?? $minVotes);

            if ($competition !== null) {
                // El vot es publica sempre, encara que la competició no sigui
                // cap de les tres: una fila rara ha de sortir a la llista, no
                // desaparèixer. Al rànquing, en canvi, no hi compta.
                $announcement['bestFinal']['votes'][] = [
                    'player'      => $name,
                    'competition' => (string) $competition,
                    'votes'       => $votes,
                ];

                if (in_array($competition, ACTION_COMPETITIONS, true)) {
                    $bestFinalVotes[$competition] = ($bestFinalVotes[$competition] ?? 0) + $votes;
                }
            }

            continue;
        }

        if ($type === 'sureToBeQualified') {
            $teamId = (int) ($data['team'] ?? ($data[0] ?? -1));
            $points = (int) ($data['points'] ?? $allowedVotes[$playerId] ?? $minVotes);

            if ($teamId > 0) {
                $announcement['sureToBeQualified'][] = [
                    'player' => $name,
                    'team'   => actionsTeamName($teamNames, $teamId),
                    'points' => $points,
                ];
            }

            continue;
        }

        // Les altres són les accions per competició (#malDia, #socElMillor,
        // #guanyarOMorir): el JSON és la llista de competicions on s'ha activat.
        if (isset($announcement[$type])) {
            $competitions = array_values(array_filter($data, 'is_string'));

            if (count($competitions) > 0) {
                $announcement[$type][] = ['player' => $name, 'values' => $competitions];
            }
        }
    }

    // El resultat de #dobleORes: qui queda a 0 punts i qui suma doble. Els
    // vots totals per equip es guarden pel nom perquè el text del resum puga
    // dir "Arsenal (3 vots, sumarà 0 punts)".
    if (count($doubleOrNothingVoted) > 0) {
        $doubleOrNothingTotals = actionsVoteTotals($doubleOrNothingVoted);
        $result                = actionsDoubleOrNothingResult($doubleOrNothingTotals, $teamCompetitions, $teamRankings);

        foreach ($doubleOrNothingTotals as $teamId => $votes) {
            $announcement['doubleOrNothing']['totals'][actionsTeamName($teamNames, (int) $teamId)] = (int) $votes;
        }

        foreach ($result as $competition => $teams) {
            // Un segon pot ser un equip sense vots: ha de sortir amb "0 vots".
            foreach (array_merge($teams['top'], $teams['second']) as $teamId) {
                $teamName = actionsTeamName($teamNames, (int) $teamId);

                if (!array_key_exists($teamName, $announcement['doubleOrNothing']['totals'])) {
                    $announcement['doubleOrNothing']['totals'][$teamName] = 0;
                }
            }

            $announcement['doubleOrNothing']['result'][$competition] = [
                'top'    => array_map(fn($teamId) => actionsTeamName($teamNames, $teamId), $teams['top']),
                'second' => array_map(fn($teamId) => actionsTeamName($teamNames, $teamId), $teams['second']),
            ];
        }
    }

    // El boicot de #kosAmbEscuts: els més votats, amb els escuts que els salven.
    // Si ningú no ha votat però l'acció estava oberta (hi ha alguna fila), el
    // boicot el decidixen els millors de la classificació.
    $kosByStandings = $kosRowSeen['kosAndShields']
        && count($kosVotes['kosAndShields']) === 0
        && count($teamPoints) > 0;

    foreach (actionsMostVotedTeams(actionsVoteTotals($kosVotes['kosAndShields']), KOS_BOICOT_TEAMS, $teamRankings, $kosByStandings ? $teamPoints : []) as $teamId) {
        $announcement['kosAndShields']['boicot'][] = [
            'team'       => actionsTeamName($teamNames, $teamId),
            'shieldedBy' => $kosShieldedBy[$teamId] ?? [],
        ];
    }

    $announcement['kosAndShields']['byStandings'] = $kosByStandings;

    // El boicot de #kosPais: cada país suma els punts de tots els seus equips, i
    // eixa suma decidix l'empat i, si no hi ha vots, quins dos països queden.
    $paisPoints = [];

    foreach ($teamNames as $teamId => $teamName) {
        $country = $teamCountries[(int) $teamId] ?? '';

        if ($country === '') {
            continue;
        }

        $paisPoints[$country] = ($paisPoints[$country] ?? 0) + ($teamPoints[(int) $teamId] ?? 0);
    }

    $kosPaisPoints   = $kosRowSeen['kosPais'] ? $paisPoints : [];
    $paisByStandings = $kosRowSeen['kosPais']
        && count($kosVotes['kosPais']) === 0
        && count($paisPoints) > 0;

    foreach (actionsMostVotedGroups($kosVotes['kosPais'], KOS_BOICOT_GROUPS, $kosPaisPoints) as $pais) {
        $announcement['kosPais']['boicot'][] = ['grup' => $pais];
    }

    $announcement['kosPais']['byStandings'] = $paisByStandings;

    // El boicot de #kosPots: igual, però el grup és el pot global i es mostra
    // amb l'etiqueta de la competició (CHL 1, EUL 3...).
    $potPoints = [];

    foreach ($teamNames as $teamId => $teamName) {
        $pot = (string) ($teamPots[(int) $teamId] ?? '');

        if ($pot === '' || (int) $pot <= 0) {
            continue;
        }

        $potPoints[$pot] = ($potPoints[$pot] ?? 0) + ($teamPoints[(int) $teamId] ?? 0);
    }

    $kosPotsPoints   = $kosRowSeen['kosPots'] ? $potPoints : [];
    $potsByStandings = $kosRowSeen['kosPots']
        && count($kosVotes['kosPots']) === 0
        && count($potPoints) > 0;

    foreach (actionsMostVotedGroups($kosVotes['kosPots'], KOS_BOICOT_GROUPS, $kosPotsPoints) as $pot) {
        $announcement['kosPots']['boicot'][] = ['grup' => potMenuLabel(potLabel((int) $pot))];
    }

    $announcement['kosPots']['byStandings'] = $potsByStandings;

    // El rànquing de #millorFinal.
    if (count($bestFinalVotes) > 0) {
        $announcement['bestFinal']['ranking'] = actionsBestFinalRanking($bestFinalVotes);
    }

    return $announcement;
}

/**
 * El missatge sencer que s'envia al grup.
 *
 * @param int   $matchDay     Jornada de les accions.
 * @param array $announcement Accions ja resoltes (noms, no ids), per tipus:
 *   - 'badDay', 'iAmTheBest', 'winOrDie': [['player' => 'Ivan', 'values' => ['CHL', 'EUL']], ...]
 *   - 'sureToBeQualified': [['player' => 'Ivan', 'team' => 'Barça', 'points' => 4], ...]
 *   - 'doubleOrNothing': ['votes' => [['player' => .., 'team' => ..], ...],
 *                         'totals' => ['Arsenal' => 2, 'Roma' => 1],
 *                         'result' => ['CHL' => ['top' => ['Arsenal'], 'second' => ['Roma']], ...]]
 *   - 'kosAndShields': ['kos' => [['player' => .., 'team' => ..], ...],
 *                       'shields' => [['player' => .., 'team' => ..], ...],
 *                       'boicot' => [['team' => 'Bayern', 'shieldedBy' => ['Ivan']], ...],
 *                       'byStandings' => false]
 *   - 'kosPais', 'kosPots': ['vots' => [['player' => .., 'team' => 'Spain'], ...],
 *                            'escuts' => [['player' => .., 'team' => ..], ...],
 *                            'boicot' => [['grup' => 'Spain'], ...],
 *                            'byStandings' => false]
 *   - 'bestFinal': ['votes' => [['player' => .., 'competition' => 'CHL', 'votes' => 4], ...],
 *                   'ranking' => ['CHL' => 7, 'EUL' => 4, 'COL' => 0]]
 */
function actionsPublishText(int $matchDay, array $announcement): string
{
    $sections = [];

    foreach (ACTION_PUBLISH_ORDER as $type) {
        $section = actionsSectionText($type, $announcement[$type] ?? []);

        if ($section !== '') {
            $sections[] = $section;
        }
    }

    // Sense cap secció no hi ha res a dir: una fila d'#dobleORes oberta sense
    // vots no ha de fer que el grup reba només la capçalera.
    if (count($sections) === 0) {
        return '';
    }

    return 'Accions de la jornada ' . $matchDay . ":\n\n" . implode("\n\n", $sections);
}
