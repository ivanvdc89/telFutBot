DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    match_day   INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    -- Una sola fila per equip, jornada i competició. Sense aquesta clau, tornar
    -- a executar getResults.php duplica els resultats: els punts no es compten
    -- dues vegades (el motor llegeix només la primera fila), però les dades
    -- queden brutes.
    UNIQUE KEY u_team_matchday (team_id, match_day, competition)
);
