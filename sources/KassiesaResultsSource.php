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

    /** D'on ha eixit la pàgina, si venia de la xarxa. */
    private ?array $page = null;

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

        // Un fitxer que no és la pàgina de partits (una descàrrega que ha eixit
        // malament, un repte anti-bots...) no s'ha de confondre amb una jornada
        // sense resultats.
        if (!self::looksLikeMatchesPage($html)) {
            throw new RuntimeException(
                "El fitxer $path no pareix la pàgina de partits de kassiesa "
                . "(no hi ha cap capçalera de competició)."
            );
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
        $page = self::fetch($url, $cachePath, $refresh, $ttl);

        $source       = new self(self::parse($page['html']));
        $source->page = [
            'fromCache' => $page['fromCache'],
            'cacheAge'  => $page['cacheAge'],
            'error'     => $page['error'],
            'detail'    => $page['detail'],
        ];

        return $source;
    }

    /**
     * D'on ha eixit la pàgina, per poder-ho dir als missatges.
     *
     * @return array{fromCache:bool, cacheAge:?int, error:?string, detail:?string}|null
     *         Null si la font no és la xarxa (per exemple, un fitxer amb --source).
     */
    public function page(): ?array
    {
        return $this->page;
    }

    /**
     * Contingut de la pàgina, amb còpia local.
     *
     * Si la xarxa falla i hi ha una còpia antiga, s'hi cau: una jornada amb dades
     * d'ahir és millor que no poder treballar, i el fitxer porta la data. Però
     * l'origen es torna a qui crida, perquè "no hi ha cap partit" i "la còpia
     * local és d'abans que es publicassen" no es poden confondre.
     *
     * @return array{html:string, fromCache:bool, cacheAge:?int, error:?string, detail:?string}
     */
    public static function fetch(string $url, string $cachePath, bool $refresh = false, int $ttl = 3600): array
    {
        $age = self::cacheAge($cachePath);

        if (!$refresh && self::cacheIsFresh($age, $ttl)) {
            $cached = file_get_contents($cachePath);

            if ($cached !== false && self::looksLikeMatchesPage($cached)) {
                return self::pageResult($cached, true, $age);
            }
        }

        $response = self::download($url);

        // Un 404, un error del servidor o un repte anti-bots no són resultats: si
        // es parsejaren, getResults.php diria "cap partit publicat" i ningú no
        // sabria que el problema és la descàrrega. Per això, quan la resposta no
        // és la pàgina de partits, se'n desa el cos i un extracte.
        if ($response['html'] === null || !self::looksLikeMatchesPage($response['html'])) {
            $detail = self::rejectionDetail($url, $response, $cachePath);

            if ($age !== null) {
                $cached = file_get_contents($cachePath);

                if ($cached !== false && self::looksLikeMatchesPage($cached)) {
                    return self::pageResult($cached, true, $age, self::shortReason($response), $detail);
                }
            }

            throw new RuntimeException($detail);
        }

        self::store($cachePath, $response['html']);

        return self::pageResult($response['html'], false, 0);
    }

    /**
     * Resultat de fetch(), amb una forma comuna.
     *
     * @return array{html:string, fromCache:bool, cacheAge:?int, error:?string, detail:?string}
     */
    private static function pageResult(string $html, bool $fromCache, ?int $age, ?string $error = null, ?string $detail = null): array
    {
        return [
            'html'      => $html,
            'fromCache' => $fromCache,
            'cacheAge'  => $age,
            'error'     => $error,
            'detail'    => $detail,
        ];
    }

    /**
     * Segons des de l'última escriptura de la còpia local, o null si no n'hi ha.
     *
     * Pot ser negatiu si la data de modificació és al futur (rellotge desajustat
     * o un fitxer copiat amb la data d'origen).
     */
    public static function cacheAge(string $path): ?int
    {
        $mtime = is_file($path) ? @filemtime($path) : false;

        return $mtime === false ? null : time() - $mtime;
    }

    /**
     * Cert si la còpia local es pot fer servir tal qual.
     *
     * Una data de modificació al futur no compta com a fresca: si no, la còpia
     * es quedaria per sempre i la pàgina no es tornaria a descarregar mai.
     */
    public static function cacheIsFresh(?int $age, int $ttl): bool
    {
        return $age !== null && $age >= 0 && $age < $ttl;
    }

    /**
     * Cert si el text pareix la pàgina de partits de kassiesa.
     *
     * Es mira la capçalera de competició, que és el que busca el parser. No es
     * mira la classe de la taula: si kassiesa hi afegeix una classe o canvia les
     * cometes, la pàgina continua servint i no s'ha de rebutjar.
     *
     * Servix per a no prendre una pàgina d'error o un repte anti-bots per una
     * jornada sense partits.
     */
    public static function looksLikeMatchesPage(string $html): bool
    {
        return str_contains($html, 'cupheader');
    }

    /**
     * Descarrega la pàgina.
     *
     * Torna el cos (null si ha fallat) i les dades de la resposta, que són les
     * que permeten entendre per què no servix: codi HTTP, tipus de contingut i
     * URL final.
     *
     * @return array{html:?string, error:?string, status:int, contentType:string, url:string}
     */
    private static function download(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'telFutBot getResults (https://kassiesa.net)',
            CURLOPT_FAILONERROR    => true,
            // Que curl negocie i descomprimisca: si el servidor comprimira la
            // resposta sense que li ho demanàrem, el cos seria binari.
            CURLOPT_ACCEPT_ENCODING => '',
        ]);
        $html  = curl_exec($ch);
        $error = curl_error($ch);

        $response = [
            'html'        => $html === false || $html === '' ? null : (string) $html,
            'error'       => $error !== '' ? $error : ($html === false || $html === '' ? 'resposta buida' : null),
            'status'      => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'contentType' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'url'         => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];

        curl_close($ch);

        return $response;
    }

    /** Motiu curt, per posar-lo en una línia de capçalera. */
    private static function shortReason(array $response): string
    {
        $reason = $response['error'] ?? 'la resposta no pareix la pàgina de partits';

        if ($response['html'] !== null) {
            $reason .= sprintf(' (HTTP %d, %d bytes)', $response['status'], strlen($response['html']));
        }

        return $reason;
    }

    /**
     * Explica què ha arribat i en desa el cos, per poder-ho revisar.
     *
     * Sense vore la resposta no es pot saber si el problema és un error del
     * servidor, un repte anti-bots o un canvi en la pàgina.
     *
     * @param array{html:?string, error:?string, status:int, contentType:string, url:string} $response
     */
    private static function rejectionDetail(string $url, array $response, string $cachePath): string
    {
        $lines = [
            "No s'ha pogut descarregar $url",
            '  ' . ($response['error'] ?? 'la resposta no pareix la pàgina de partits'),
        ];

        if ($response['html'] === null) {
            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            '  HTTP %d · %s · %d bytes · final: %s',
            $response['status'],
            $response['contentType'] !== '' ? $response['contentType'] : 'sense tipus',
            strlen($response['html']),
            $response['url'] !== '' ? $response['url'] : $url
        );

        $rejected = preg_replace('/\.html$/', '', $cachePath) . '.rejected.html';
        self::store($rejected, $response['html']);

        if (is_file($rejected)) {
            $lines[] = "  Resposta desada a $rejected";
        }

        $snippet = self::snippet($response['html']);

        if ($snippet !== '') {
            $lines[] = "  Comença així: $snippet";
        }

        return implode("\n", $lines);
    }

    /** Extracte d'una pàgina en una sola línia, per als missatges d'error. */
    private static function snippet(string $html, int $length = 240): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $html));

        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }

    /** Desa la pàgina a la còpia local, sense deixar mai un fitxer a mitges. */
    private static function store(string $cachePath, string $html): void
    {
        $dir = dirname($cachePath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $tmp = $cachePath . '.' . getmypid() . '.tmp';

        // Escriptura atòmica: si dos processos coincideixen, mai no es llegeix un
        // fitxer a mig escriure.
        if (@file_put_contents($tmp, $html) === false) {
            @unlink($tmp);

            return;
        }

        if (!@rename($tmp, $cachePath)) {
            @unlink($tmp);
        }
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
