DROP TABLE IF EXISTS substitutions;
CREATE TABLE substitutions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    player_id   INT NOT NULL,
    old_team_id INT NOT NULL,
    new_team_id INT NOT NULL,
    competition INT NOT NULL,
    pending     TINYINT(1) NOT NULL DEFAULT 1
);