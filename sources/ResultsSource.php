<?php

/**
 * Una font de resultats.
 *
 * El joc només necessita una cosa: donada una competició i una jornada, els
 * partits que s'hi van jugar amb el resultat de cada un. Aquesta interfície és
 * el contracte, i getResults.php no sap d'on surten les dades.
 *
 * Avui només hi ha KassiesaResultsSource. Si mai cal canviar a una API, n'hi ha
 * prou de fer una altra implementació i canviar-la a getResults.php: ni el
 * càlcul de punts ni la resolució de noms no s'han de tocar.
 *
 * Un partit és un array amb aquestes claus:
 *
 *   home          nom de l'equip local, tal com el dona la font
 *   away          nom de l'equip visitant
 *   home_country  país del local (codi o nom, per als missatges)
 *   away_country  país del visitant
 *   home_goals    gols del local
 *   away_goals    gols del visitant
 *
 * Els partits que la font encara no té jugats (resultat buit) NO es retornen:
 * una jornada futura dona una llista buida, no partits sense gols.
 */
interface ResultsSource
{
    /**
     * Partits d'una competició i una jornada.
     *
     * @param string $competition Codi del joc: 'CHL', 'EUL' o 'COL'.
     * @param int    $matchDay    Jornada del joc (1-8 fase de lliga, 10-18 eliminatòries).
     *
     * @return array<int, array{home:string, away:string, home_country:string, away_country:string, home_goals:int, away_goals:int}>
     */
    public function getMatches(string $competition, int $matchDay): array;
}
