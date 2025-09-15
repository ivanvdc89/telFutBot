DROP TABLE IF EXISTS player_teams;
CREATE TABLE player_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    team_id INT NOT NULL
);