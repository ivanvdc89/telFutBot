<?php

require_once(__DIR__ . '/ResultsSource.php');
require_once(__DIR__ . '/../config/results.php');

/**
 * Llegeix els resultats de la pàgina de partits de kassiesa.net.
 *
 * ESTRUCTURA DE LA PÀGINA
 *
 * Una taula per competició. Dins de cada taula:
 *
 *   <th><div class="cupheader">CHAMPIONS LEAGUE</div></th>
 *   <th><div class="roundheader">League Stage</div></th>
 *   <tr><td>Local</td><td>Ang</td><td>Visitant</td><td>Esp</td>
 *       <td class="result">2-0</td><td class="result"></td></tr>
 *   ...
 *   <tr><td colspan="6"> &nbsp; </td></tr>   <- separador de bloc
 *
 * La fila separadora parteix la fase de lliga en jornades: el primer bloc és la
 * jornada 1, el següent la 2, etc. A les eliminatòries no cal, perquè cada fila
 * ja porta les dues columnes de resultat: la primera és l'anada (a casa del
 * primer equip) i la segona la tornada (a casa del segon).
 *
 * Les files de tanda de penals porten class="pstext"/"psresult" i s'ignoren: no
 * reparteixen punts.
 *
 * El parser és tolerant amb la resta: qualsevol fila que no reconeix es salta.
 */
class KassiesaResultsSource implements ResultsSource
{
    /**
     * @var array<string, array<string, array<int, array<int, array{home:string, away:string, home_country:string, away_country:string, leg1:string, leg2:string}>>>>
     *      [competició][ronda][bloc][] = partit
     */
    private array $rounds;

    private function __construct(array $rounds)
    {
        $this->rounds = $rounds;
    }

    public static function fromHtml(string $html): self
    {
        return new self(self::parse($html));
    }

    public static function fromFile(string $path): self
    {
        $html = @file_get_contents($path);

        if ($html === false) {
            throw new RuntimeException("No s'ha pogut llegir el fitxer de resultats: $path");
        }

        return self::fromHtml($html);
    }

    /**
     * Descarrega la pàgina (o la reutilitza de la còpia local) i la parseja.
     *
     * @param bool $refresh Ignora la còpia local encara que sigui fresca.
     */
    public static function fromUrl(string $url, string $cachePath, bool $refresh = false, int $ttl = 3600): self
    {
        return self::fromHtml(self::download($url, $cachePath, $refresh, $ttl));
    }

    /**
     * Contingut de la pàgina, amb còpia local.
     *
     * Si la xarxa falla i hi ha una còpia antiga, s'hi cau: una jornada amb dades
     * d'ahir és millor que no poder treballar, i el fitxer porta la data.
     */
    public static function download(string $url, string $cachePath, bool $refresh = false, int $ttl = 3600): string
    {
        $fresh = is_file($cachePath) && (time() - filemtime($cachePath)) < $ttl;

        if (!$refresh && $fresh) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'telFutBot getResults (https://kassiesa.net)',
            CURLOPT_FAILONERROR    => true,
        ]);
        $html = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $html === '') {
            if (is_file($cachePath)) {
                fwrite(STDERR, "Avís: no s'ha pogut descarregar $url ($error); s'usa la còpia local.\n");

                return (string) file_get_contents($cachePath);
            }

            throw new RuntimeException("No s'ha pogut descarregar $url: $error");
        }

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            // Escriptura atòmica: si dos processos coincideixen, mai no es llegeix
            // un fitxer a mig escriure.
            $tmp = $cachePath . '.' . getmypid() . '.tmp';
            if (file_put_contents($tmp, $html) !== false) {
                @rename($tmp, $cachePath);
            }
        }

        return $html;
    }

    public function getMatches(string $competition, int $matchDay): array
    {
        $label = RESULTS_COMPETITION_LABELS[$competition] ?? null;

        if ($label === null || !isset($this->rounds[$label])) {
            return [];
        }

        // La lligueta de la Conference comença a la jornada 2 del joc, i la 1 i la
        // 8 no es juguen (les omple ResultsPlanner amb zeros).
        $round = resultsLeagueRound($competition, $matchDay);
        if ($round !== null) {
            return $this->leagueStageMatches($label, $round);
        }

        return $this->knockoutMatches($label, $matchDay);
    }

    /** Bloc de la fase de lliga: el bloc 1 és la primera ronda de la lligueta. */
    private function leagueStageMatches(string $label, int $round): array
    {
        $block = $round - 1;
        $rows  = $this->rounds[$label][RESULTS_LEAGUE_STAGE][$block] ?? [];
        $out   = [];

        foreach ($rows as $row) {
            if ($row['leg1'] === '') {
                continue;
            }

            $goals = self::parseScore($row['leg1']);
            if ($goals === null) {
                continue;
            }

            $out[] = self::match($row['home'], $row['away'], $row['home_country'], $row['away_country'], $goals[0], $goals[1]);
        }

        return $out;
    }

    /**
     * Eliminatòries: cada fila és una eliminatòria amb les dues columnes.
     *
     * L'anada es juga a casa del primer equip i la tornada a casa del segon, i
     * per això a la tornada s'intercanvien local i visitant.
     */
    private function knockoutMatches(string $label, int $matchDay): array
    {
        foreach (RESULTS_KNOCKOUT_ROUNDS as $roundLabel => $base) {
            if ($matchDay === $base) {
                $leg = 1;
            } elseif ($matchDay === $base + 1) {
                $leg = 2;
            } else {
                continue;
            }

            $out = [];
            foreach ($this->rounds[$label][$roundLabel] ?? [] as $block) {
                foreach ($block as $row) {
                    $score = $row['leg' . $leg];
                    if ($score === '') {
                        continue;
                    }

                    $goals = self::parseScore($score);
                    if ($goals === null) {
                        continue;
                    }

                    if ($leg === 1) {
                        $out[] = self::match($row['home'], $row['away'], $row['home_country'], $row['away_country'], $goals[0], $goals[1]);
                    } else {
                        $out[] = self::match($row['away'], $row['home'], $row['away_country'], $row['home_country'], $goals[0], $goals[1]);
                    }
                }
            }

            return $out;
        }

        return [];
    }

    private static function match(string $home, string $away, string $homeCountry, string $awayCountry, int $homeGoals, int $awayGoals): array
    {
        return [
            'home'         => $home,
            'away'         => $away,
            'home_country' => $homeCountry,
            'away_country' => $awayCountry,
            'home_goals'   => $homeGoals,
            'away_goals'   => $awayGoals,
        ];
    }

    /** "2-0" -> [2, 0]; qualsevol altra cosa, null. */
    public static function parseScore(string $score): ?array
    {
        $score = trim(str_replace(["\xc2\xa0", '–', '—'], [' ', '-', '-'], $score));

        if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $score, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Converteix l'HTML en l'estructura [competició][ronda][bloc][partits].
     */
    public static function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom      = new DOMDocument();

        // Sense això, DOMDocument assumeix ISO-8859-1 i trenca els accents.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException("No s'ha pogut interpretar l'HTML de resultats.");
        }

        $xpath = new DOMXPath($dom);
        $rounds = [];

        foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " t1 ")]') as $table) {
            $competition = null;
            $round       = null;
            $block       = 0;

            foreach ($table->getElementsByTagName('tr') as $tr) {
                $header = $xpath->query('.//div[contains(@class, "cupheader")]', $tr);
                if ($header->length > 0) {
                    $competition = self::clean($header->item(0)->textContent);
                    $round       = null;
                    $block       = 0;
                    continue;
                }

                $header = $xpath->query('.//div[contains(@class, "roundheader")]', $tr);
                if ($header->length > 0) {
                    $round = self::clean($header->item(0)->textContent);
                    $block = 0;
                    continue;
                }

                if ($competition === null || $round === null) {
                    continue;
                }

                // Files de tanda de penals: no reparteixen punts.
                if ($xpath->query('.//td[contains(@class, "pstext") or contains(@class, "psresult")]', $tr)->length > 0) {
                    continue;
                }

                $cells = [];
                foreach ($tr->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->tagName === 'td') {
                        $cells[] = self::clean($child->textContent);
                    }
                }

                // Fila separadora de bloc (una sola cel·la buida amb colspan).
                if (count($cells) === 1 && $cells[0] === '') {
                    $block++;
                    continue;
                }

                if (count($cells) < 6) {
                    continue;
                }

                $rounds[$competition][$round][$block][] = [
                    'home'         => $cells[0],
                    'home_country' => $cells[1],
                    'away'         => $cells[2],
                    'away_country' => $cells[3],
                    'leg1'         => $cells[4],
                    'leg2'         => $cells[5],
                ];
            }
        }

        return $rounds;
    }

    /** Text net: sense salts de línia ni espais de més (inclòs el &nbsp;). */
    private static function clean(string $text): string
    {
        $text = str_replace(["\xc2\xa0", "\r", "\n", "\t"], ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
