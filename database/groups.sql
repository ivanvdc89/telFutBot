-- `groups` és paraula reservada a MySQL 8.0.2 i posteriors (per les funcions de
-- finestra), així que cal citar-la sempre. A MariaDB i MySQL 5.7 no ho és, però
-- citar-la funciona igual als quatre llocs.
DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) DEFAULT NULL
);