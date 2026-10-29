<?php

/**
 * El teclat del menú /accions, a partir dels interruptors de la temporada.
 *
 * És aquí i no dins de basic.php perquè aixina es pot provar: el menú és el que
 * veuen els jugadors, i una llista mal mesclada es nota de seguida.
 *
 * La idea és que NO hi haja cap llista escrita a mà: tancar una fase la treu
 * del menú tota sola, i obrir-la la hi torna a posar.
 *
 * /dobleORes i el boicot de KO que està en joc ixen al menú; els altres dos
 * boicots no, perquè no es poden votar eixa jornada. /segurQuePasse i
 * /millorFinal només tenen efecte en fases concretes i es continuen escrivint a
 * mà.
 *
 * @param string $kosActive Quin boicot de KO està en joc (KOS_ACTIVE), o '' si
 *                          eixa jornada no n'hi ha cap.
 *
 * @return array Files de botons, buit si no hi ha res disponible.
 */
function actionsMenuRows(bool $substitutionsOpen, bool $actionsOpen, string $kosActive): array
{
    $rows = [];

    if ($substitutionsOpen) {
        $rows[] = ['/substitució'];
    }

    if ($actionsOpen) {
        $rows[]     = ['/malDia', '/socElMillor', '/guanyarOMorir'];
        $kosCommand = kosCommand($kosActive);

        if ($kosCommand !== '') {
            $rows[] = [$kosCommand];
        }
    }

    return $rows;
}

/**
 * El comandament del boicot de KO que toca, a partir del seu tipus.
 *
 * El tipus és el de la taula actions (KOS_ACTIVE); el comandament és el que el
 * jugador escriu o el botó que polsa. Els tres boicots són accions distintes i
 * tenen el seu propi botó: no hi ha un /kos amb maneres.
 *
 * @return string El comandament, o cadena buida si el tipus no és cap boicot.
 */
function kosCommand(string $kosActive): string
{
    return match ($kosActive) {
        'kosAndShields' => '/kos',
        'kosPais'       => '/kosPais',
        'kosPots'       => '/kosPots',
        default         => '',
    };
}

/**
 * El menú de /inici.
 *
 * És el mateix que estava escrit dins de basic.php. Viure ací el fa provable i,
 * sobretot, permet que el botó "Inici" que acompanyen les pantalles de resultat
 * òbriga exactament el mateix menú.
 *
 * @return array Files de botons.
 */
function startMenuRows(): array
{
    return [
        ['/accions', '/data', '/equips'],
        ['/normes', '/configuració'],
    ];
}
