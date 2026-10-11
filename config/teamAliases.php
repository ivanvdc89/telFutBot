<?php

/**
 * Noms d'equip que la font i la base de dades escriuen diferent.
 *
 * La clau és el nom de la font normalitzat (minúscules, sense accents ni
 * espais, vegeu TeamNameResolver::normalize) i el valor, el nom de l'equip a
 * la taula teams. El valor es busca per nom normalitzat, així que les
 * majúscules i els accents no hi importen.
 *
 * Aquest fitxer és l'escapatòria per als casos que el matcher automàtic no pot
 * resoldre: noms en una altra llengua, abreviatures o equips que la font
 * anomena d'una manera que no comparteix cap tros amb el nom de la BD. Quan
 * getResults.php no troba un equip, imprimeix el nom de la font i els
 * candidats: normalment n'hi ha prou d'afegir una línia aquí.
 *
 * NOMÉS CAL AFRONTAR-HI ELS EQUIPS DE LA FASE DE LLIGA I LES ELIMINATÒRIES: la
 * ronda prèvia no es processa mai.
 */
return [
    // kassiesa: "Internazionale" / BD: "Inter"
    'internazionale' => 'Inter',

    // Noms en una altra llengua que no comparteixen cap tros amb el de la BD.
    'redstarbelgrade' => 'CrvenaZvezda',
    'crvenazvezda'    => 'CrvenaZvezda',
    'staderennais'    => 'Rennes',
    'ofiheraklion'    => 'OFICrete',

    // Abreviatures habituals dels mitjans (claus normalitzades: sense espais).
    'psg'     => 'ParisSaintGermain',
    'mancity' => 'ManchesterCity',
    'manutd'  => 'ManchesterUnited',
];
