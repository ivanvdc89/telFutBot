<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/matchDayTeamPoint.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/teamResult.php");
require_once("models/action.php");
require_once("models/substitution.php");

$playersRepo             = new Player();
$teamsRepo               = new Team();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$teamResultRepo          = new TeamResult();
$actionsRepo             = new Action();
$substitutionsRepo       = new Substitution();
$matchDay                = 6;

$actions = $actionsRepo->getActions($matchDay, 'iAmTheBest');
$players = [
    'CHL' => [],
    'EUL' => [],
    'COL' => [],
];
foreach ($actions as $action) {
    $actionData = json_decode($action['data'], true);
    foreach ($actionData as $competition) {
        $players[$competition][] = $action['player_id'];
    }
}

$bestCHL = 5; // TODO: must be calculated dinamically
$bestEUL = 5; // TODO: must be calculated dinamically
$bestCOL = 5; // TODO: must be calculated dinamically

$nothingTeams = [];
$doubleTeams  = [];
$koTeams      = [1, 4, 10, 49];

$players = $playersRepo->getAllPlayers();
foreach ($players as $player) {
    $playerId     = $player['id'];
    $lastMatchDay = $matchDayPlayerPointRepo->getLastMatchDayByPlayer($player['id']);
    $playerTeams  = $teamsRepo->getTeamsByPlayerId($player['id']);
    $chlPoints    = 0;
    $chlWins      = 0;
    $eulPoints    = 0;
    $eulWins      = 0;
    $colPoints    = 0;
    $colWins      = 0;

    $shieldTeams = [];
    $actions     = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'kosAndShields');
    if(count($actions) > 0) {
        $kosAndShieldsData = json_decode($actions[0]['data'], true);
        foreach ($kosAndShieldsData['shields'] as $team) {
            $shieldTeams[] = $team;
        }
    }

    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchDay($team['id'], $matchDay);
        if (count($teamResult) == 0) {
            continue;
        }

        $action = [];
        $totalPoints = $teamResult[0]['points'];
        if (in_array($team['id'], $nothingTeams) ) {
            $action = ["type" => "dobleORes", "result" => "Res"];
            $totalPoints = 0;
        }
        if (in_array($team['id'], $doubleTeams) ) {
            $action = ["type" => "dobleORes", "result" => "Doble"];
            $totalPoints = 2 * $teamResult[0]['points'];
        }
        if (in_array($team['id'], $koTeams) ) {
            if (in_array($team['id'], $shieldTeams) ) {
                $action = ["type" => "kosAmbEscuts", "result" => "Escut"];
                $totalPoints = $teamResult[0]['points'];
            } else {
                $action = ["type" => "kosAmbEscuts", "result" => "KO"];
                $totalPoints = 0;
            }
        }

        if($teamResult[0]['competition'] === "CHL"){
            $chlPoints += $totalPoints;
            if($teamResult[0]['points'] > 2) {
                $chlWins++;
            }
        }
        if($teamResult[0]['competition'] === "EUL"){
            $eulPoints += $totalPoints;
            if($teamResult[0]['points'] > 2) {
                $eulWins++;
            }
        }
        if($teamResult[0]['competition'] === "COL"){
            $colPoints += $totalPoints;
            if($teamResult[0]['points'] > 2) {
                $colWins++;
            }
        }

        $matchDayTeamPointRepo->addMatchDayTeamPoint(
            $playerId,
            $matchDay,
            $pot,
            $team['id'],
            $teamResult[0]['points'],
            empty($action) ? '' : json_encode($action),
            $totalPoints
        );
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'badDay');
    $chlPointsAfterAction = $chlPoints;
    $eulPointsAfterAction = $eulPoints;
    $colPointsAfterAction = $colPoints;
    $chlAction = [];
    $eulAction = [];
    $colAction = [];
    if (isset($actions[0])) {
        $actionData = json_decode($actions[0]['data'], true);
        foreach ($actionData as $competition) {
            if ($competition === "CHL") {
                if ($chlPoints > 5) {
                    $chlAction[] = ["type" => "malDia", "result" => "KO"];
                    $chlPointsAfterAction = 2;
                } else {
                    $chlAction[] = ["type" => "malDia", "result" => "OK"];
                    $chlPointsAfterAction = 9;
                }
            }
            if ($competition === "EUL") {
                if ($eulPoints > 5) {
                    $eulAction[] = ["type" => "malDia", "result" => "KO"];;
                    $eulPointsAfterAction = 2;
                } else {
                    $eulAction[] = ["type" => "malDia", "result" => "OK"];
                    $eulPointsAfterAction = 9;
                }
            }
            if ($competition === "COL") {
                if ($colPoints > 6) {
                    $colAction[] = ["type" => "malDia", "result" => "KO"];;
                    $colPointsAfterAction = 2;
                } else {
                    $colAction[] = ["type" => "malDia", "result" => "OK"];
                    $colPointsAfterAction = 12;
                }
            }
        }
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'iAmTheBest');
    foreach ($actions as $action) {
        $actionData = json_decode($action['data'], true);
        foreach ($actionData as $competition) {
            if ($competition === "CHL") {
                if ($chlPoints >= $bestCHL) {
                    $chlAction[]          = ["type" => "socElMillor", "result" => "OK"];
                    $chlPointsAfterAction += 3;
                } else {
                    $chlAction[]          = ["type" => "socElMillor", "result" => "KO"];
                    $chlPointsAfterAction -= 3;
                }
            }
            if ($competition === "EUL") {
                if ($eulPoints >= $bestEUL) {
                    $eulAction[]          = ["type" => "socElMillor", "result" => "OK"];
                    $eulPointsAfterAction += 3;
                } else {
                    $eulAction[]          = ["type" => "socElMillor", "result" => "KO"];
                    $eulPointsAfterAction -= 3;
                }
            }
            if ($competition === "COL") {
                if ($colPoints >= $bestCOL) {
                    $colAction[]          = ["type" => "socElMillor", "result" => "OK"];
                    $colPointsAfterAction += 4;
                } else {
                    $colAction[]          = ["type" => "socElMillor", "result" => "KO"];
                    $colPointsAfterAction -= 4;
                }
            }
        }
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'winOrDie');
    foreach ($actions as $action) {
        $actionData = json_decode($action['data'], true);
        foreach ($actionData as $competition) {
            if ($competition === "CHL") {
                $result               = -4 + $chlWins * 2;
                $chlAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $chlPointsAfterAction += $result;
            }
            if ($competition === "EUL") {
                $result               = -4 + $eulWins * 2;
                $eulAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $eulPointsAfterAction += $result;
            }
            if ($competition === "COL") {
                $result               = -4 + $colWins * 2;
                $colAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $colPointsAfterAction += $result;
            }
        }
    }

    $substitutions = $substitutionsRepo->getMatchDaySubstitutionsByPlayerId($matchDay, $playerId);
    foreach ($substitutions as $substitution) {
        $pointsCost = 0 - $substitution['points_cost'];
        if ($substitution['competition'] === "CHL") {
            $chlAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $chlPointsAfterAction += $pointsCost;
        }
        if ($substitution['competition'] === "EUL") {
            $eulAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $eulPointsAfterAction += $pointsCost;
        }
        if ($substitution['competition'] === "COL") {
            $colAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $colPointsAfterAction += $pointsCost;
        }
    }

    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        $matchDay,
        $chlPoints,
        json_encode($chlAction),
        $chlPointsAfterAction,
        $chlPointsAfterAction + $lastMatchDay['chl_total'],
        $eulPoints,
        json_encode($eulAction),
        $eulPointsAfterAction,
        $eulPointsAfterAction + $lastMatchDay['eul_total'],
        $colPoints,
        json_encode($colAction),
        $colPointsAfterAction,
        $colPointsAfterAction + $lastMatchDay['col_total'],
        '',
        $chlPointsAfterAction + $eulPointsAfterAction + $colPointsAfterAction,
        $lastMatchDay['total'] + $chlPointsAfterAction + $eulPointsAfterAction + $colPointsAfterAction
    );
}
