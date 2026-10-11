<?php

require_once(__DIR__ . '/../config/teamAliases.php');

/**
 * Troba l'equip de la base de dades a partir del nom que dona la font.
 *
 * El problema: kassiesa escriu "Olympique Lyon", "Omonia Nicosia" o "Sparta
 * Praha", i la taula teams guarda "Lyon", "Omonia" i "SpartaPrague". No hi ha
 * cap clau comuna, així que s'ha de semblar els noms.
 *
 * COM ES SEMBLA
 *
 * 1. Normalització: minúscules, sense accents ni signes, amb els caràcters que
 *    no es transliteren sols (ø, æ, ð...) resolts a mà.
 * 2. Àlies: si el nom normalitzat és a config/teamAliases.php, aquell és el bo.
 * 3. Trossos: del nom de la font se'n treuen tots els trossos contigus de
 *    paraules ("Olympique Lyon" dona "olympique", "lyon" i "olympiquelyon").
 *    Cada tros es compara amb el nom de la BD:
 *      - igual            -> coincidència exacta
 *      - prefix o sufix   -> molt probable (la font hi afegeix ciutat o club)
 *      - conté / és contingut -> probable
 * 4. Semblança de caràcters (similar_text) per a les diferències d'escriptura:
 *    "Olympiakos" vs "Olympiacos", "Villarreal" vs "VilaReal".
 *
 * QUAN NO ES FIA
 *
 * Si el millor candidat no arriba a MIN_SCORE, o si el segon és a prop
 * (MIN_MARGIN), el resultat surt com a no resolt. És deliberat: un equip
 * equivocat rep punts que no toquen i ningú no se n'adona. getResults.php
 * imprimeix aquests casos perquè s'afegixin a l'arxiu d'àlies.
 *
 * Els candidats es filtren per competició: un equip només pot jugar la seva, i
 * això treu molta ambigüitat ("Sporting" és CP a la CHL i Braga a l'EUL).
 */
class TeamNameResolver
{
    /** Puntuació mínima per donar un nom per bo. */
    public const MIN_SCORE = 660.0;

    /** Diferència mínima amb el segon candidat quan el millor no és exacte. */
    public const MIN_MARGIN = 60.0;

    /** A partir d'aquí es considera coincidència segura encara que hi hagi empat. */
    public const SURE_SCORE = 900.0;

    /**
     * Longitud mínima d'un tros perquè valgui com a prefix, sufix o contingut.
     *
     * Els trossos curts ("real", "city", "club") surten a mig futbol i no
     * serveixen per identificar un equip. Si són el nom sencer, ja guanyen per
     * coincidència exacta abans d'arribar aquí.
     */
    private const MIN_PARTIAL_SPAN = 5;

    /**
     * Paraules de club que la BD sovint no hi és. No és una llista de noms
     * prohibits: si la BD els porta, la coincidència exacta ja guanya abans de
     * treure'ls.
     */
    private const NOISE = [
        'fc', 'ac', 'afc', 'as', 'nk', 'sk', 'sc', 'cf', 'rc', 'rcd', 'ogc',
        'sv', 'vfl', 'vfb', 'tsg', 'bsc', 'fsv', 'sl', 'ss', 'ssc', 'us', 'ud',
        'sd', 'cp', '05', 'de', 'of', 'the', 'club', 'if', 'bk', 'fk', 'kk',
    ];

    /** Noms de ciutat que la font afegeix i que es poden treure sense por. */
    private const CITY = [
        'piraeus', 'thessaloniki', 'bialystok', 'nicosia', 'almaty', 'razgrad',
        'bergen', 'tetovo', 'telaviv', 'lisbon',
    ];

    /** Equivalències entre llengües. */
    private const SYNONYMS = [
        'praha'      => 'prague',
        'munchen'    => 'munich',
        'kobenhavn'  => 'copenhagen',
        'olympiakos' => 'olympiacos',
    ];

    /** [ [id, name, norm], ... ] per competició. */
    private array $byCompetition = [];

    /** @var array<string, string> nom normalitzat -> nom de la BD */
    private array $aliases;

    /**
     * @param array<int, array{id:int|string, name:string, competition?:string}> $teams
     * @param array<string, string>                                              $aliases
     */
    public function __construct(array $teams, array $aliases = [])
    {
        $this->byCompetition = [];

        foreach ($teams as $team) {
            $competition = $team['competition'] ?? '';
            $this->byCompetition[$competition][] = [
                'id'   => (int) $team['id'],
                'name' => (string) $team['name'],
                'norm' => self::normalize((string) $team['name']),
            ];
        }

        $this->aliases = $aliases;
    }

    /** Resol un nom de la font a l'equip de la BD, o null si no es fia. */
    public function resolve(string $name, ?string $competition = null): ?array
    {
        $result = $this->rank($name, $competition);

        if ($result['id'] === null) {
            return null;
        }

        return ['id' => $result['id'], 'name' => $result['name']];
    }

    /**
     * Els millors candidats per a un nom, amb la puntuació.
     *
     * @return array{id:?int, name:?string, score:float, ambiguous:bool, candidates:array<int, array{id:int, name:string, score:float}>}
     */
    public function rank(string $name, ?string $competition = null): array
    {
        $candidates = $this->candidates($competition);
        $normalized = self::normalize($name);
        $tokens     = self::tokens($name);
        $spans      = self::spans($tokens, $name);
        $scored     = [];

        foreach ($candidates as $candidate) {
            $scored[] = [
                'id'    => $candidate['id'],
                'name'  => $candidate['name'],
                'score' => $this->score($normalized, $tokens, $spans, $candidate['norm']),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $top    = $scored[0] ?? null;
        $second = $scored[1] ?? null;

        if ($top === null || $top['score'] < self::MIN_SCORE) {
            return [
                'id'         => null,
                'name'       => null,
                'score'      => $top['score'] ?? 0.0,
                'ambiguous'  => false,
                'candidates' => array_slice($scored, 0, 3),
            ];
        }

        // Si el segon és a prop, el nom és ambigu i val més no endevinar.
        if ($top['score'] < self::SURE_SCORE
            && $second !== null
            && ($top['score'] - $second['score']) < self::MIN_MARGIN
        ) {
            return [
                'id'         => null,
                'name'       => null,
                'score'      => $top['score'],
                'ambiguous'  => true,
                'candidates' => array_slice($scored, 0, 3),
            ];
        }

        return [
            'id'         => $top['id'],
            'name'       => $top['name'],
            'score'      => $top['score'],
            'ambiguous'  => false,
            'candidates' => array_slice($scored, 0, 3),
        ];
    }

    /**
     * Els equips d'una competició, amb el seu id i nom.
     *
     * El fan servir les jornades simulades, que han de donar un 0 a tots els
     * equips de la competició.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function teamsInCompetition(string $competition): array
    {
        $teams = [];

        foreach ($this->byCompetition[$competition] ?? [] as $team) {
            $teams[] = ['id' => $team['id'], 'name' => $team['name']];
        }

        return $teams;
    }

    /** @return array<int, array{id:int, name:string, norm:string}> */
    private function candidates(?string $competition): array
    {
        if ($competition === null || $competition === '') {
            $all = [];
            foreach ($this->byCompetition as $teams) {
                $all = array_merge($all, $teams);
            }

            return $all;
        }

        return $this->byCompetition[$competition] ?? [];
    }

    /** Minúscules, sense accents, sense res que no sigui lletra o número. */
    public static function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', self::transliterate($text));
    }

    /** Minúscules i sense accents, però conservant la separació de paraules. */
    private static function transliterate(string $text): string
    {
        // iconv no desfà aquests caràcters: se saben de bestreta.
        $text = strtr($text, [
            'ø' => 'o', 'Ø' => 'O', 'æ' => 'ae', 'Æ' => 'AE', 'å' => 'a', 'Å' => 'A',
            'ß' => 'ss', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ł' => 'l', 'ı' => 'i',
        ]);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($transliterated !== false) {
            $text = $transliterated;
        }

        return strtolower($text);
    }

    /**
     * Paraules del nom de la font, sense clubs ni ciutats prescindibles.
     *
     * @return array<int, string>
     */
    private static function tokens(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', self::transliterate($name), -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];
        foreach ($words as $word) {
            $word = self::SYNONYMS[$word] ?? $word;
            if (in_array($word, self::NOISE, true) || in_array($word, self::CITY, true)) {
                continue;
            }
            $tokens[] = $word;
        }

        return $tokens;
    }

    /**
     * Trossos contigus del nom de la font, normalitzats.
     *
     * @param array<int, string> $tokens
     *
     * @return array<int, string>
     */
    private static function spans(array $tokens, string $name): array
    {
        if ($tokens === []) {
            $flat = self::normalize($name);

            return strlen($flat) >= 4 ? [$flat] : [];
        }

        $spans = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $span = '';
            for ($j = $i; $j < $count; $j++) {
                $span .= $tokens[$j];
                if (strlen($span) >= 4) {
                    $spans[$span] = true;
                }
            }
        }

        return array_keys($spans);
    }

    /**
     * @param array<int, string> $tokens
     * @param array<int, string> $spans
     */
    private function score(string $normalized, array $tokens, array $spans, string $candidate): float
    {
        if (isset($this->aliases[$normalized]) && self::normalize($this->aliases[$normalized]) === $candidate) {
            return 1000.0;
        }

        if ($normalized === $candidate) {
            return 1000.0;
        }

        $best = 0.0;

        foreach ($spans as $span) {
            $diff = abs(strlen($span) - strlen($candidate));

            if ($span === $candidate) {
                $best = max($best, 1000.0 - $diff);
                continue;
            }

            // Un tros curt només val si és el nom sencer. "real" és un tros de
            // "VilaReal", però també ho és de mig futbol espanyol: si s'acceptés
            // com a sufix, "Real Sociedad" cauria a "VilaReal" quan el seu equip
            // no hi fos.
            if (strlen($span) < self::MIN_PARTIAL_SPAN) {
                continue;
            }

            if (strlen($span) <= strlen($candidate)) {
                // El nom de la BD és més llarg: la font hi afegeix el club o la
                // ciutat ("FC Salzburg" -> "RedBullSalzburg").
                if (str_starts_with($candidate, $span) || str_ends_with($candidate, $span)) {
                    $best = max($best, 800.0 - 8 * $diff);
                } elseif (str_contains($candidate, $span)) {
                    $best = max($best, 700.0 - 8 * $diff);
                }
                continue;
            }

            // El nom de la BD és més curt. Aquí és on es colen els falsos
            // positius: "Lillestrøm" comença per "Lille". Només s'accepta si el
            // candidat és una paraula sencera del nom o gairebé tan llarg.
            if (!in_array($candidate, $tokens, true) && $diff > 2) {
                continue;
            }

            if (str_starts_with($span, $candidate) || str_ends_with($span, $candidate)) {
                $best = max($best, 800.0 - 8 * $diff);
            } elseif (str_contains($span, $candidate)) {
                $best = max($best, 700.0 - 8 * $diff);
            }
        }

        // Diferències d'escriptura que cap tros no cobreix.
        similar_text($normalized, $candidate, $percent);
        $best = max($best, $percent * 7.6);

        return $best;
    }
}
