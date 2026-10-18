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
 *
 * El pot global només és la clau de la base de dades. Les comandes i els botons
 * que veu el jugador fan servir l'etiqueta per competició (CHL_1...COL_4):
 * "pot 5" no diu res, "EUL_1" sí. potLabel() i potFromLabel() fan el canvi.
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

/**
 * Etiqueta del pot per a l'usuari: CHL_1...CHL_4, EUL_1...EUL_4, COL_1...COL_4.
 *
 * El pot global (1-12) és la clau de la base de dades, però els números 5-12 no
 * diuen res de la competició. Les comandes i els botons fan servir l'etiqueta.
 */
function potLabel(int $globalPot): string
{
    if ($globalPot < 1 || !isset(POT_COMPETITIONS[$globalPot], POT_NUMBERS[$globalPot])) {
        return (string) $globalPot;
    }

    return POT_COMPETITIONS[$globalPot] . '_' . POT_NUMBERS[$globalPot];
}

/**
 * Totes les etiquetes de pot, en l'ordre CHL 1-4, EUL 1-4, COL 1-4.
 *
 * @return array<int, string>
 */
function potLabels(): array
{
    $labels = [];

    foreach (POTS as $globalPot) {
        $labels[] = potLabel($globalPot);
    }

    return $labels;
}

/**
 * El pot global (1-12) d'una etiqueta com CHL_1 o EUL_4.
 *
 * També accepta el número global 1-12: els teclats antics que encara tenen els
 * jugadors a Telegram (pot 5, pot 12...) continuen funcionant.
 *
 * @return int|null Null si l'etiqueta no és cap pot.
 */
function potFromLabel(string $label): ?int
{
    if (preg_match('/^(CHL|EUL|COL)_([1-4])$/i', $label, $matches) === 1) {
        $competition = strtoupper($matches[1]);
        $number      = (int) $matches[2];

        foreach (POTS as $globalPot) {
            if (POT_COMPETITIONS[$globalPot] === $competition
                && POT_NUMBERS[$globalPot] === $number
            ) {
                return $globalPot;
            }
        }

        return null;
    }

    if (ctype_digit($label) && (int) $label >= 1 && (int) $label <= 12) {
        return (int) $label;
    }

    return null;
}
