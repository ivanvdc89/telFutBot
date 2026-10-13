DROP TABLE IF EXISTS team_results;

CREATE TABLE team_results (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    team_id     INT NOT NULL,
    points      INT DEFAULT NULL,
    -- Els gols del partit de l'equip: els que va marcar i els que va rebre.
    --
    -- Són la matèria primera de la classificació d'equips: sumats per jornades
    -- donen els gols a favor i en contra de tota la competició, que és el que
    -- desempata els equips amb els mateixos punts (per exemple, per decidir quin
    -- equip queda KO quan hi ha empat de vots a #kosAmbEscuts).
    goals_for     INT NOT NULL DEFAULT 0,
    goals_against INT NOT NULL DEFAULT 0,
    -- Diferència de gols: gols a favor menys gols en contra. És positiva si
    -- l'equip va marcar més que el rival, zero si va empatar i negativa si en va
    -- rebre més.
    --
    -- Es desa calculada perquè la classificació la pugui sumar directament
    -- (SUM(goal_difference)), però no és una dada nova: la desa el model a
    -- partir de goals_for i goals_against, i no pot quedar descompensada.
    goal_difference INT NOT NULL DEFAULT 0,
    match_day   INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    -- Una sola fila per equip, jornada i competició. Els resultats no
    -- s'actualitzen mai: aquesta clau fa que un segon intent de desar el mateix
    -- equip i la mateixa jornada falli, i getResults.php ho detecta, avisa i no
    -- escriu res.
    UNIQUE KEY u_team_matchday (team_id, match_day, competition)
);
