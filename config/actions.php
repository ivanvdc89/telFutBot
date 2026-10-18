<?php

/**
 * El teclat del menú /accions, a partir dels interruptors de la temporada.
 *
 * És aquí i no dins de basic.php perquè aixina es pot provar: el menú és el que
 * veuen els jugadors, i una llista mal mesclada es nota de seguida.
 *
 * La idea és que NO hi hagi cap llista escrita a mà: tancar una fase la treu
 * del menú tota sola, i obrir-la la hi torna a posar.
 *
 * /dobleORes és l'única votació que ix al menú. Les altres (/kos,
 * /segurQuePasse i /millorFinal) només tenen efecte en fases concretes i es
 * continuen escrivint a mà.
 *
 * @return array Files de botons, buit si no hi ha res disponible.
 */
function actionsMenuRows(bool $substitutionsOpen, bool $actionsOpen): array
{
    $rows = [];

    if ($substitutionsOpen) {
        $rows[] = ['/substitució'];
    }

    if ($actionsOpen) {
        $rows[] = ['/malDia', '/socElMillor', '/guanyarOMorir'];
        $rows[] = ['/dobleORes'];
    }

    return $rows;
}
