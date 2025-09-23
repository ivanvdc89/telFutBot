DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    matchday    INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    UNIQUE KEY u_team_matchday (team_id, matchday, competition)
);

INSERT INTO team_results (team_id, points, matchday, competition) VALUES (72, 0, 1, 'COL');