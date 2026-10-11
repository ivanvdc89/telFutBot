DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    match_day   INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    -- Una sola fila per equip, jornada i competició. Els resultats no
    -- s'actualitzen mai: aquesta clau fa que un segon intent de desar el mateix
    -- equip i la mateixa jornada falli, i getResults.php ho detecta, avisa i no
    -- escriu res.
    UNIQUE KEY u_team_matchday (team_id, match_day, competition)
);
