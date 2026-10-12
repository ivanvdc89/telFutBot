DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    -- Diferència de gols del partit de l'equip: gols a favor menys gols en
    -- contra. És positiva si l'equip va marcar més que el rival, zero si va
    -- empatar i negativa si en va rebre més.
    --
    -- És la matèria primera de la classificació d'equips: sumada per jornades
    -- dona la diferència de gols de tota la competició, que és el que desempata
    -- els equips amb els mateixos punts (per exemple, per decidir quin equip
    -- queda KO quan hi ha empat de vots a #kosAmbEscuts).
    goal_difference INT NOT NULL DEFAULT 0,
    match_day   INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    -- Una sola fila per equip, jornada i competició. Els resultats no
    -- s'actualitzen mai: aquesta clau fa que un segon intent de desar el mateix
    -- equip i la mateixa jornada falli, i getResults.php ho detecta, avisa i no
    -- escriu res.
    UNIQUE KEY u_team_matchday (team_id, match_day, competition)
);
