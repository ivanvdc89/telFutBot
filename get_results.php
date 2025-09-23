<?php
// db.php (reuse your connection)
$pdo = new PDO('mysql:host=localhost;dbname=fut_ko;charset=utf8mb4', 'myappuser', '123ggg');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiToken = "d64472bab24d104e00eed8e33ce7f7e12baa93d9";
$url      = "https://api.soccerdataapi.com/livescores/?auth_token={$apiToken}";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "gzip", // important
    CURLOPT_HTTPHEADER => [
        "Accept-Encoding: gzip",
        "Content-Type: application/json"
    ]
]);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("cURL error: " . curl_error($ch));
}
curl_close($ch);

// Decode response
$data = json_decode($response, true);
if ($data === null) {
    die("Invalid JSON response: " . $response);
}

if (!isset($data['data']) || !is_array($data['data'])) {
    die("Unexpected API format:\n" . print_r($data, true));
}

// Determine where matches are in response
// Based on examples in the docs, likely in top level array, e.g. $data['data'] or $data['livescores']
// Adjust as needed; here I’ll assume $data['data'] is an array of match objects
$matchesArray = $data['data'] ?? $data['livescores'] ?? [];

// Prepare statements
// For team_results table
$stmTeamRes = $pdo->prepare("
    INSERT INTO team_results (team_id, points, matchday, competition)
    VALUES (:team_id, :points, :matchday, :competition)
    ON DUPLICATE KEY UPDATE
       points = VALUES(points)
");

foreach ($data['data'] as $leagueEntry) {
    $leagueId = $leagueEntry['league_id'] ?? null;
    $leagueName = $leagueEntry['league_name'] ?? '';
    $matches = $leagueEntry['matches'] ?? [];

    foreach ($matches as $m) {
        if (!isset($m['status']) || strtolower($m['status']) !== 'finished') {
            continue;
        }

    // Parse needed fields
    $fixtureId = $m['id'] ?? null;  // match unique id
    $leagueId  = $m['league']['id'] ?? null;
    $leagueName = $m['league']['name'] ?? '';
    $dateStr   = ($m['date'] ?? '') . ' ' . ($m['time'] ?? '');
    // Convert to DATETIME, adjust if needed; assuming date/time in known format

    $homeTeamId = $m['teams']['home']['id'] ?? null;
    $homeTeamName = $m['teams']['home']['name'] ?? '';
    $awayTeamId = $m['teams']['away']['id'] ?? null;
    $awayTeamName = $m['teams']['away']['name'] ?? '';

    // Full-time goals
    $homeGoals = $m['goals']['home_ft_goals'] ?? null;
    $awayGoals = $m['goals']['away_ft_goals'] ?? null;

    if ($fixtureId === null || $leagueId === null || $homeTeamId === null || $awayTeamId === null) {
        // insufficient data
        continue;
    }

    // Determine matchday: this depends on the API data. If API has a “round” or “matchday” or “stage” info:
    $matchday = null;
    if (isset($m['round'])) {
        // if round is numeric
        if (is_numeric($m['round'])) {
            $matchday = intval($m['round']);
        } else {
            // maybe "Group Stage - 3" etc → extract digits
            if (preg_match('/(\d+)/', $m['round'], $mr)) {
                $matchday = intval($mr[1]);
            }
        }
    }
    if ($matchday === null) {
        // fallback to 0 or skip
        $matchday = 0;
    }

    // Competition code: map leagueName or leagueId to your ENUM (CHL, EUL, COL, etc.)
    // For example, if leagueName contains "Champions League", set competition = 'CHL'
    $competitionEnum = null;
    if (stripos($leagueName, 'Champions League') !== false) {
        $competitionEnum = 'CHL';
    } elseif (stripos($leagueName, 'Europa League') !== false) {
        $competitionEnum = 'EUL';
    } elseif (stripos($leagueName, 'Conference League') !== false) {
        $competitionEnum = 'COL';
    } else {
        // skip if competition not one of your ENUMs
        continue;
    }

    // Determine points for the two teams
    if ($homeGoals > $awayGoals) {
        $pointsHome = 3;
        $pointsAway = 0;
    } elseif ($homeGoals < $awayGoals) {
        $pointsHome = 0;
        $pointsAway = 3;
    } else {
        $pointsHome = 1;
        $pointsAway = 1;
    }

    // Update team_results for home
    $stmTeamRes->execute([
        ':team_id'     => $homeTeamId,
        ':points'      => $pointsHome,
        ':matchday'    => $matchday,
        ':competition'=> $competitionEnum
    ]);

    // Update team_results for away
    $stmTeamRes->execute([
        ':team_id'     => $awayTeamId,
        ':points'      => $pointsAway,
        ':matchday'    => $matchday,
        ':competition'=> $competitionEnum
    ]);
}
}

echo "Live matches & team results updated.\n";

