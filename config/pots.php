<?php

/**
 * Els 12 pots de la temporada, en un sol lloc.
 *
 * Eren tres arrays a dalt de basic.php, i els fan servir /equips, /add i
 * /data. Com que /data viu a DataAction, els pots són aquí pel mateix motiu que
 * la jornada a matchDay.php: una sola font de veritat que poden compartir el
 * bot i les accions.
 *
 * El pot global va de 1 a 12, i cada competició en té 4: CHL 1-4, EUL 5-8,
 * COL 9-12. Els arrays porten una posició 0 de farciment perquè la clau sigui
 * el pot global tal qual, sense restar-ne res.
 */

/**
 * Pots globals, en ordre.
 */
const POTS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

/**
 * Número de pot dins de la competició, indexat pel pot global.
 */
const POT_NUMBERS = [0, 1, 2, 3, 4, 1, 2, 3, 4, 1, 2, 3, 4];

/**
 * Competició de cada pot global, indexada pel pot global.
 */
const POT_COMPETITIONS = [
    'X',
    'CHL', 'CHL', 'CHL', 'CHL',
    'EUL', 'EUL', 'EUL', 'EUL',
    'COL', 'COL', 'COL', 'COL',
];
