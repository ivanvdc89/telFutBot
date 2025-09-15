DROP TABLE IF EXISTS player_teams;
CREATE TABLE player_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id VARCHAR(50) NOT NULL,
    team_id VARCHAR(50) NOT NULL
);