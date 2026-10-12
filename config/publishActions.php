<?php

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
 *     tret dels qui porten escut.
 *   - #millorFinal: la final més votada dona +6 al guanyador, la segona +2, i
 *     la menys votada -2 al perdedor.
 *
 * ELS EMPATS
 *   - Als vots per equip, els empatats mantenen l'ordre en què s'han votat per
 *     primera vegada: arsort() és estable des de PHP 8.0.
 *   - A #dobleORes només compten els equips amb algun vot. El joc diu que els
 *     empats a 0 vots es resolen per la classificació, però aquí no hi ha cap
 *     classificació d'equips a mà, i inventar-se-la seria pitjor que deixar la
 *     competició sense segon.
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
    'bestFinal'         => '#millorFinal',
    'sureToBeQualified' => '#segurQuePasse',
];

/**
 * Competicions del joc, en l'ordre en què s'expliquen.
 */
const ACTION_COMPETITIONS = ['CHL', 'EUL', 'COL'];

/**
 * Equips que queden a 0 punts per #kosAmbEscuts. La norma diu "els 4 més
 * votats", i és el mateix número que explica /normes accions.
 */
const KOS_BOICOT_TEAMS = 4;

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
 * votat per primera vegada.
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
 * Els equips més votats, en ordre, sense comptar els que no han rebut cap vot.
 *
 * @param array<int, int> $totals [team_id => vots]
 *
 * @return array<int, int> ids d'equip
 */
function actionsMostVotedTeams(array $totals, int $howMany): array
{
    $teams = [];

    foreach ($totals as $teamId => $votes) {
        if (count($teams) >= $howMany) {
            break;
        }

        if ($votes > 0) {
            $teams[] = $teamId;
        }
    }

    return $teams;
}

/**
 * El resultat de #dobleORes: per competició, l'equip o equips més votats i el
 * segon o segons.
 *
 * @param array<int, int>    $totals           [team_id => vots]
 * @param array<int, string> $teamCompetitions [team_id => 'CHL'|'EUL'|'COL']
 *
 * @return array<string, array{top: array<int, int>, second: array<int, int>}>
 */
function actionsDoubleOrNothingResult(array $totals, array $teamCompetitions): array
{
    $result = [];
    foreach (ACTION_COMPETITIONS as $competition) {
        $result[$competition] = ['top' => [], 'second' => []];
    }

    // Els vots de cada competició, sense perdre l'ordre global (que ja va de
    // més votat a menys).
    $byCompetition = [];
    foreach ($totals as $teamId => $votes) {
        $competition = $teamCompetitions[$teamId] ?? null;

        if ($votes <= 0 || $competition === null || !isset($result[$competition])) {
            continue;
        }

        $byCompetition[$competition][$teamId] = $votes;
    }

    foreach ($byCompetition as $competition => $votesByTeam) {
        $mostVotes      = max($votesByTeam);
        $secondMostVotes = null;

        foreach ($votesByTeam as $teamId => $votes) {
            if ($votes === $mostVotes) {
                $result[$competition]['top'][] = $teamId;

                continue;
            }

            // Com que la llista va ordenada, el segon valor que apareix és el
            // segon més votat.
            if ($secondMostVotes === null) {
                $secondMostVotes = $votes;
            }

            if ($votes === $secondMostVotes) {
                $result[$competition]['second'][] = $teamId;
            }
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
        $lines = array_merge($lines, actionsSubBlock(actionsDoubleOrNothingText($data['result'] ?? [])));
    } elseif ($type === 'kosAndShields') {
        $lines = actionsVoteLines($data['kos'] ?? [], 'de KO a');

        foreach ($data['shields'] ?? [] as $shield) {
            $lines[] = '- ' . $shield['player'] . ': 1 escut a ' . $shield['team'];
        }

        $lines = array_merge($lines, actionsSubBlock(actionsKosBoicotText($data['boicot'] ?? [])));
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
 * El resum de #dobleORes: qui queda a 0 punts i qui suma doble a cada
 * competició.
 *
 * @param array<string, array{top: array<int, string>, second: array<int, string>}> $result Noms, no ids.
 *
 * @return array<int, string>
 */
function actionsDoubleOrNothingText(array $result): array
{
    if (count($result) === 0) {
        return [];
    }

    $lines = ["Equips més votats (el més votat no suma cap punt i el segon suma doble):"];

    foreach ($result as $competition => $teams) {
        $top    = $teams['top'] ?? [];
        $second = $teams['second'] ?? [];

        if (count($top) === 0) {
            $lines[] = '- ' . $competition . ': cap vot';

            continue;
        }

        $line = '- ' . $competition . ': ' . actionsJoinNames($top) . ' (a 0 punts)';
        $line .= count($second) > 0
            ? '; ' . actionsJoinNames($second) . ' (punts dobles)'
            : '; cap segon equip amb vots';

        $lines[] = $line;
    }

    return $lines;
}

/**
 * El resum de #kosAmbEscuts: els més votats que queden a 0 punts, amb els
 * escuts que els salven.
 *
 * @param array<int, array{team: string, shieldedBy: array<int, string>}> $boicot
 *
 * @return array<int, string>
 */
function actionsKosBoicotText(array $boicot): array
{
    if (count($boicot) === 0) {
        return [];
    }

    $lines = ['Els ' . min(KOS_BOICOT_TEAMS, count($boicot)) . ' equips més votats (no sumen punts):'];

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
 * @param array<int, string> $teamCompetitions [team_id => 'CHL'|'EUL'|'COL'].
 * @param int                $minVotes         Vots de la regla de posició per a les files velles.
 */
function actionsAnnouncement(
    array $actionRows,
    array $allowedVotes,
    array $playerNames,
    array $teamNames,
    array $teamCompetitions,
    int $minVotes
): array {
    $announcement = [
        'badDay'            => [],
        'iAmTheBest'        => [],
        'winOrDie'          => [],
        'sureToBeQualified' => [],
        'doubleOrNothing'   => ['votes' => [], 'result' => []],
        'kosAndShields'     => ['kos' => [], 'shields' => [], 'boicot' => []],
        'bestFinal'         => ['votes' => [], 'ranking' => []],
    ];

    $doubleOrNothingVoted = [];   // ids d'equip votats, un element per vot
    $kosVoted             = [];
    $kosShieldedBy        = [];   // [team_id => [noms dels qui l'han protegit]]
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

        if ($type === 'kosAndShields') {
            foreach ($data['kos'] ?? [] as $teamId) {
                $teamId = (int) $teamId;

                $kosVoted[]                             = $teamId;
                $announcement['kosAndShields']['kos'][] = [
                    'player' => $name,
                    'team'   => actionsTeamName($teamNames, $teamId),
                ];
            }

            foreach ($data['shields'] ?? [] as $teamId) {
                $teamId = (int) $teamId;

                if (!in_array($name, $kosShieldedBy[$teamId] ?? [], true)) {
                    $kosShieldedBy[$teamId][] = $name;
                }

                $announcement['kosAndShields']['shields'][] = [
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

    // El resultat de #dobleORes: qui queda a 0 punts i qui suma doble.
    if (count($doubleOrNothingVoted) > 0) {
        $result = actionsDoubleOrNothingResult(actionsVoteTotals($doubleOrNothingVoted), $teamCompetitions);

        foreach ($result as $competition => $teams) {
            $announcement['doubleOrNothing']['result'][$competition] = [
                'top'    => array_map(fn($teamId) => actionsTeamName($teamNames, $teamId), $teams['top']),
                'second' => array_map(fn($teamId) => actionsTeamName($teamNames, $teamId), $teams['second']),
            ];
        }
    }

    // El boicot de #kosAmbEscuts: els més votats, amb els escuts que els salven.
    foreach (actionsMostVotedTeams(actionsVoteTotals($kosVoted), KOS_BOICOT_TEAMS) as $teamId) {
        $announcement['kosAndShields']['boicot'][] = [
            'team'       => actionsTeamName($teamNames, $teamId),
            'shieldedBy' => $kosShieldedBy[$teamId] ?? [],
        ];
    }

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
 *                         'result' => ['CHL' => ['top' => ['Arsenal'], 'second' => ['Roma']], ...]]
 *   - 'kosAndShields': ['kos' => [['player' => .., 'team' => ..], ...],
 *                       'shields' => [['player' => .., 'team' => ..], ...],
 *                       'boicot' => [['team' => 'Bayern', 'shieldedBy' => ['Ivan']], ...]]
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

    return 'Accions de la jornada ' . $matchDay . ":\n\n" . implode("\n\n", $sections);
}
