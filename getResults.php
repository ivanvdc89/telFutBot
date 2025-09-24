<?php
//$pdo = new PDO('mysql:host=localhost;dbname=fut_ko;charset=utf8mb4', 'myappuser', '123ggg');
//$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiToken = "%API_TOKEN";
const CHAMPIONS_LEAGUE_ID = 310;
const EUROPE_LEAGUE_ID = 326;
const CONFERENCE_LEAGUE_ID = 198;

$VALID_LEAGUES_IDS = [CHAMPIONS_LEAGUE_ID, EUROPE_LEAGUE_ID, CONFERENCE_LEAGUE_ID];

$url = "https://api.soccerdataapi.com/livescores/?auth_token=$apiToken";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "gzip",
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

$data = json_decode($response, true);
if ($data === null) {
    die("Invalid JSON response: " . $response);
}

if (!isset($data['results']) || !is_array($data['results'])) {
    die("Unexpected API format:\n" . print_r($data, true));
}

/*
$stmTeamRes = $pdo->prepare("
    INSERT INTO team_results (team_id, points, matchday, competition)
    VALUES (:team_id, :points, :match_day, :competition)
    ON DUPLICATE KEY UPDATE
       points = VALUES(points)
");
*/

$ids = [];
foreach ($data['results'] as $result) {
    if (!in_array($result['league_id'], $VALID_LEAGUES_IDS)) {
        $ids[] = $result['league_name'];
        continue;
    }

    if (!isset($result['status']) || strtolower($result['status']) !== 'finished') {
        continue;
    }

    $leagueName = $result['league']['name'] ?? '';
    $homeTeamId = $result['teams']['home']['id'] ?? null;
    $awayTeamId = $result['teams']['away']['id'] ?? null;
    $homeGoals  = $result['goals']['home_ft_goals'] ?? null;
    $awayGoals  = $result['goals']['away_ft_goals'] ?? null;

    if ($homeTeamId === null || $awayTeamId === null) {
        continue;
    }

    $matchDay = null;
    if (isset($result['round'])) {
        if (is_numeric($result['round'])) {
            $matchDay = intval($result['round']);
        } else {
            if (preg_match('/(\d+)/', $result['round'], $mr)) {
                $matchDay = intval($mr[1]);
            }
        }
    }
    if ($matchDay === null) {
        $matchDay = 0;
    }

    $competitionEnum = null;
    if (stripos($leagueName, 'Champions League') !== false) {
        $competitionEnum = 'CHL';
    } elseif (stripos($leagueName, 'Europa League') !== false) {
        $competitionEnum = 'EUL';
    } elseif (stripos($leagueName, 'Conference League') !== false) {
        $competitionEnum = 'COL';
    } else {
        continue;
    }

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
    print_r([
        ':team_id'    => $homeTeamId,
        ':points'     => $pointsHome,
        ':match_day'  => $matchDay,
        ':competition'=> $competitionEnum
    ]);

    // Update team_results for away
    print_r([
        ':team_id'    => $awayTeamId,
        ':points'     => $pointsAway,
        ':match_day'  => $matchDay,
        ':competition'=> $competitionEnum
    ]);
}

print_r($ids);
echo "Live matches & team results updated.\n";

