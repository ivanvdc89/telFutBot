DROP TABLE IF EXISTS team_standings;

-- La classificació d'equips, recalculada a partir de team_results.
--
-- És una taula derivada: no s'hi escriu mai a mà i es pot esborrar i tornar a
-- calcular sempre que calgui (calculateTeamStandings.php la reescriu sencera).
-- Existeix perquè consultar-la és el que necessiten les accions de votació per
-- desempatar (quin equip queda KO amb els mateixos vots, quin és el segon més
-- votat...) i perquè, guardada, sempre està disponible i es pot mirar.
CREATE TABLE team_standings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    competition ENUM('CHL', 'EUL', 'COL') NOT NULL,
    -- Posició a la classificació de la seua competició: 1 és el primer.
    -- Es desa calculada perquè el desempat sigui una sola consulta.
    ranking INT NOT NULL,
    -- Punts de la COMPETICIÓ (3/1/0), no els del joc: a la lligueta de la
    -- Conference el joc en dona 4 per victòria (perquè juga 6 jornades i no 8) i
    -- a la competició en valen 3. Les jornades simulades de la Conference no
    -- n'aporten cap.
    points INT NOT NULL DEFAULT 0,
    goals_for INT NOT NULL DEFAULT 0,
    goals_against INT NOT NULL DEFAULT 0,
    -- Gols a favor menys en contra. Es desa perquè és el primer criteri de
    -- desempat i perquè la taula es pugui llegir d'una ullada.
    goal_difference INT NOT NULL DEFAULT 0,
    -- Quantes files de team_results s'han sumat. A la Conference hi compten
    -- també les jornades simulades, que hi són amb 0.
    match_days INT NOT NULL DEFAULT 0,
    -- Una fila per equip: un equip només juga una competició.
    UNIQUE KEY u_team (team_id)
);
