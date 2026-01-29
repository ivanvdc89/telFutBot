<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/team.php");
require_once("models/teamResult.php");

function normalize($str) {
    // remove accents
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    // lower, remove non-alpha chars, collapse spaces
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9 ]/i', ' ', $str);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

function findTeamId($name) {
    $teamsRepo = new Team();
    $teams     = $teamsRepo->getAllTeams();
    $clean     = normalize($name);

    $bestId = null;
    $bestScore = 0;

    foreach ($teams as $t) {
        $tClean = normalize($t["name"]);

        // similarity score (0–100)
        similar_text($clean, $tClean, $pct);

        // also check levenshtein distance
        $lev = levenshtein($clean, $tClean);
        $levScore = max(0, 100 - $lev * 5);

        // combined score
        $score = ($pct * 0.7) + ($levScore * 0.3);

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $t["id"];
        }
    }

    // threshold avoids wrong matches
    return ($bestScore >= 45) ? $bestId : null;
}

// Converts single match line to SQL inserts
function processMatch($line, $matchday, $competition) {
    if (!preg_match('/(.+?)\s+(\d+)[–-](\d+)\s+(.+)/u', $line, $m)) {
        echo "INVALID LINE: $line\n";
        return;
    }

    $homeTeam  = trim($m[1]);
    $homeGoals = (int)$m[2];
    $awayGoals = (int)$m[3];
    $awayTeam  = trim($m[4]);

    // remove country names from teams, keep last word groups
    $homeTeamClean = preg_replace('/\b(Kosovo|Bosnia and Herzegovina|Republic of Ireland|Norway|Serbia|Bulgaria|Portugal|Scotland|Hungary|Belgium|Denmark|France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech Republic|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $homeTeam);
    $awayTeamClean = preg_replace('/\b(Kosovo|Bosnia and Herzegovina|Republic of Ireland|Norway|Serbia|Bulgaria|Portugal|Scotland|Hungary|Belgium|Denmark|France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech Republic|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $awayTeam);

    $homeTeamClean = trim($homeTeamClean);
    $awayTeamClean = trim($awayTeamClean);

    $homeId = findTeamId($homeTeamClean);
    $awayId = findTeamId($awayTeamClean);

    if (!$homeId || !$awayId) {
        echo "❌ Missing team ID: $homeTeamClean or $awayTeamClean\n";
        return;
    }

    // Points
    if ($homeGoals > $awayGoals) {
        $homePts = 3; $awayPts = 0;
    } elseif ($awayGoals > $homeGoals) {
        $homePts = 0; $awayPts = 3;
    } else {
        $homePts = 1; $awayPts = 1;
    }

    // Output SQL
    echo "INSERT INTO team_results VALUES (NULL, $homeId, $homePts, $matchday, '$competition');\n";
    echo "INSERT INTO team_results VALUES (NULL, $awayId, $awayPts, $matchday, '$competition');\n";

    $teamResultRepo = new TeamResult();
    $teamResultRepo->addTeamResult($homeId, $homePts, $matchday, $competition);
    $teamResultRepo->addTeamResult($awayId, $awayPts, $matchday, $competition);
    echo "✅ Processed: $homeTeam - $awayTeam\n";
}

// YOUR INPUT RESULTS
$input = <<<TEXT
Aston Villa England	3–2	Austria Red Bull Salzburg
Celtic Scotland	4–2	Netherlands Utrecht
Porto Portugal	3–1	Scotland Rangers
Basel Switzerland	0–1	Czech Republic Viktoria Plzeň
Midtjylland Denmark	2–0	Croatia Dinamo Zagreb
Red Star Belgrade Serbia	1–1	Spain Celta Vigo
FCSB Romania	1–1	Turkey Fenerbahçe
Go Ahead Eagles Netherlands	0–0	Portugal Braga
Genk Belgium	2–1	Sweden Malmö FF
Lille France	1–0	Germany SC Freiburg
Maccabi Tel Aviv Israel	0–3	Italy Bologna
Nottingham Forest England	4–0	Hungary Ferencváros
Lyon France	4–2	Greece PAOK
Panathinaikos Greece	1–1	Italy Roma
Ludogorets Razgrad Bulgaria	1–0	France Nice
Real Betis Spain	2–1	Netherlands Feyenoord
Sturm Graz Austria	1–0	Norway Brann
VfB Stuttgart Germany	3–2	Switzerland Young Boys
TEXT;

// SETTINGS
$matchday    = 8;
$competition = "COL";
$competition = "CHL";
$competition = "EUL";

foreach (explode("\n", trim($input)) as $line) {
    if (trim($line) !== "")
        processMatch(trim($line), $matchday, $competition);
}
?>