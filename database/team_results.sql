DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    match_day   INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    UNIQUE KEY u_team_matchday (team_id, matchday, competition)
);
