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

// Converts single match line to SQL inserts
function processMatch($line, $matchday, $competition) {
    // Split by score using regex
    if (!preg_match('/^(.*?)\s+(\d+)[–-](\d+)\s+(.*)$/u', $line, $m)) {
        echo "Cannot parse line: $line\n";
        return;
    }

    $homeTeamRaw = trim($m[1]);
    $homeGoals   = (int)$m[2];
    $awayGoals   = (int)$m[3];
    $awayTeamRaw = trim($m[4]);

    // Extract only team name (first token before country)
    $homeTeam   = strtok($homeTeamRaw, " ");
    $awayTeam   = strtok($awayTeamRaw, " ");

    $homeId = getTeamId($homeTeam);
    $awayId = getTeamId($awayTeam);

    if (!$homeId || !$awayId) {
        echo "❌ Missing team ID: $homeTeam or $awayTeam\n";
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