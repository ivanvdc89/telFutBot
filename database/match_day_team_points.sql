DROP TABLE IF EXISTS match_day_team_points;
CREATE TABLE match_day_team_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    match_day INT NOT NULL,
    pot INT NOT NULL,
    team_id INT DEFAULT NULL,
    points INT DEFAULT NULL,
    action VARCHAR(50) DEFAULT NULL,
    total INT DEFAULT NULL
);