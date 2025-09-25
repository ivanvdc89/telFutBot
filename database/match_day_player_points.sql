DROP TABLE IF EXISTS match_day_player_points;
CREATE TABLE match_day_player_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    match_day INT NOT NULL,
    chl_points INT DEFAULT NULL,
    chl_action VARCHAR(50) DEFAULT NULL,
    chl_sum INT DEFAULT NULL,
    chl_total INT DEFAULT NULL,
    eul_points INT DEFAULT NULL,
    eul_action VARCHAR(50) DEFAULT NULL,
    eul_sum INT DEFAULT NULL,
    eul_total INT DEFAULT NULL,
    col_points INT DEFAULT NULL,
    col_action VARCHAR(50) DEFAULT NULL,
    col_sum INT DEFAULT NULL,
    col_total INT DEFAULT NULL,
    match_day_action VARCHAR(50) DEFAULT NULL,
    match_day_total INT DEFAULT NULL,
    total INT DEFAULT NULL
);