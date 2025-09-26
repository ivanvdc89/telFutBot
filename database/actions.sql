DROP TABLE IF EXISTS actions;
CREATE TABLE actions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    player_id   INT NOT NULL,
    match_day   INT NOT NULL,
    type        VARCHAR(20) DEFAULT NULL,
    data        TEXT DEFAULT NULL
);