<?php
/**
 * PLANTILLA dels secrets. Aquest fitxer SÍ que és al repositori, així que no hi
 * posis mai cap valor real.
 *
 * Copia'l a config/secrets.values.php, que està al .gitignore, i omple els
 * valors d'aquest servidor. Es fa una sola vegada: els git pull no el toquen.
 *
 *     cp config/secrets.values.example.php config/secrets.values.php
 */

return [
    // Token del bot de Telegram, el que dona BotFather.
    'telegram_token' => '',

    // Connexió a la base de dades.
    'db_host'        => 'localhost',
    'db_name'        => 'fut_ko',
    'db_user'        => '',
    'db_pass'        => '',
];
