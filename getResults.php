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
function processMatch($line, $matchDay, $competition, $extraPointsForClassifiedTeams) {
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
    echo "INSERT INTO team_results VALUES (NULL, $homeId, $homePts, $matchDay, '$competition');\n";
    echo "INSERT INTO team_results VALUES (NULL, $awayId, $awayPts, $matchDay, '$competition');\n";

    $teamResultRepo = new TeamResult();
    $teamResultRepo->addTeamResult($homeId, $homePts, $matchDay, $competition);
    $teamResultRepo->addTeamResult($awayId, $awayPts, $matchDay, $competition);
    echo "✅ Processed: $homeTeam - $awayTeam\n";
}

// YOUR INPUT RESULTS
$input = <<<TEXT
Ararat-Armenia Armenia	1–4	Czech Republic Sparta Prague
Omonia Cyprus	1–0	Spain Celta Vigo
Milan Italy	0–2	Portugal Benfica
Bayer Leverkusen Germany	2–0	Slovenia Celje
Hapoel Be'er Sheva Israel	0–0	Croatia Dinamo Zagreb
Olympiacos Greece	2–1	Poland Jagiellonia Białystok
Anderlecht Belgium	1–2	France Lyon
Sturm Graz Austria	0–0	France Rennes
Sunderland England	1–0	Netherlands AZ
OFI Greece	2–0	Germany TSG Hoffenheim
Levski Sofia Bulgaria	0–1	Austria Red Bull Salzburg
Beşiktaş Turkey	4–1	France Marseille
Celtic Scotland	1–3	Hungary Ferencváros
Crystal Palace England	4–0	Poland Lech Poznań
Viktoria Plzeň Czech Republic	0–3	Belgium Union Saint-Gilloise
Juventus Italy	5–0	Netherlands NEC
Lillestrøm Norway	1–2	Portugal Torreense
Real Sociedad Spain	1–2	England Bournemouth
TEXT;

// SETTINGS
$matchday    = 1;
//$competition = "CHL";
$competition = "EUL";
//$competition = "COL";
$extraPointsForClassifiedTeams = 1;

foreach (explode("\n", trim($input)) as $line) {
    if (trim($line) !== "")
        processMatch(trim($line), $matchday, $competition, $extraPointsForClassifiedTeams);
}
?>