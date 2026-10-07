DROP TABLE IF EXISTS player_teams;
CREATE TABLE player_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    team_id INT NOT NULL,
    -- Un equip només pot ser d'un jugador una sola vegada. Sense aquesta clau,
    -- un duplicat faria que getTeamsByPlayerId el retornés dues vegades i
    -- calculateMatchDayPoints sumés els punts d'aquell equip dos cops.
    UNIQUE KEY u_player_team (player_id, team_id)
);