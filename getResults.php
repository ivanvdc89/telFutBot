<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/team.php");
require_once("models/teamResult.php");

function getTeamId($teamName) {
    $teamsRepo = new Team();
    $team      = $teamsRepo->getTeamByName($teamName);

    return $team ? (int)$team[0]['id'] : null;
}

function normalize($str) {
    // remove accents
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    // lower, remove non-alpha chars, collapse spaces
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9 ]/i', ' ', $str);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

function findTeamId($name, $teams) {
    $clean = normalize($name);

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
    $homeTeamClean = preg_replace('/\b(France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech.*|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $homeTeam);
    $awayTeamClean = preg_replace('/\b(France|Netherlands|Ireland|Malta|Gibraltar|Bosnia.*|Sweden|Poland|Switzerland|Cyprus|Ukraine|Austria|Czech.*|Slovenia|Romania|Germany|Slovakia|Spain|Armenia|Italy|Greece|Iceland|Turkey|North Macedonia|Croatia|Finland|England)\b/i', '', $awayTeam);

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
Kairat Kazakhstan	0–1	Greece Olympiacos
Bayern Munich Germany	3–1	Portugal Sporting CP
Monaco France	1–0	Turkey Galatasaray
Atalanta Italy	2–1	England Chelsea
Barcelona Spain	2–1	Germany Eintracht Frankfurt
Inter Milan Italy	0–1	England Liverpool
PSV Eindhoven Netherlands	2–3	Spain Atlético Madrid
Union Saint-Gilloise Belgium	2–3	France Marseille
Tottenham Hotspur England	3–0	Czech Republic Slavia Prague
Qarabağ Azerbaijan	2–4	Netherlands Ajax
Villarreal Spain	2–3	Denmark Copenhagen
Athletic Bilbao Spain	0–0	France Paris Saint-Germain
Bayer Leverkusen Germany	2–2	England Newcastle United
Borussia Dortmund Germany	2–2	Norway Bodø/Glimt
Club Brugge Belgium	0–3	England Arsenal
Juventus Italy	2–0	Cyprus Pafos
Real Madrid Spain	1–2	England Manchester City
Benfica Portugal	2–0	Italy Napoli
TEXT;

// SETTINGS
$matchday    = 6;
$competition = "CHL";

foreach (explode("\n", trim($input)) as $line) {
    if (trim($line) !== "")
        processMatch(trim($line), $matchday, $competition);
}

?>