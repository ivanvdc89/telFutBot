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
    $homeTeamClean = preg_replace('/\b(Norway|Serbia|Bulgaria|Portugal|Scotland|Hungary|Belgium|Denmark|France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech Republic|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $homeTeam);
    $awayTeamClean = preg_replace('/\b(Norway|Serbia|Bulgaria|Portugal|Scotland|Hungary|Belgium|Denmark|France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech Republic|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $awayTeam);

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
Young Boys Switzerland	1–0	France Lille
Midtjylland Denmark	1–0	Belgium Genk
Utrecht Netherlands	1–2	England Nottingham Forest
Ferencváros Hungary	2–1	Scotland Rangers
Dinamo Zagreb Croatia	1–3	Spain Real Betis
Nice France	0–1	Portugal Braga
Ludogorets Razgrad Bulgaria	3–3	Greece PAOK
Sturm Graz Austria	0–1	Serbia Red Star Belgrade
Celtic Scotland	0–3	Italy Roma
Porto Portugal	2–1	Sweden Malmö FF
Basel Switzerland	1–2	England Aston Villa
FCSB Romania	4–3	Netherlands Feyenoord
Lyon France	2–1	Netherlands Go Ahead Eagles
Panathinaikos Greece	0–0	Czech Republic Viktoria Plzeň
Celta Vigo Spain	1–2	Italy Bologna
SC Freiburg Germany	1–0	Austria Red Bull Salzburg
Brann Norway	0–4	Turkey Fenerbahçe
TEXT;

// SETTINGS
$matchday    = 6;
$competition = "EUL";

foreach (explode("\n", trim($input)) as $line) {
    if (trim($line) !== "")
        processMatch(trim($line), $matchday, $competition);
}
?>