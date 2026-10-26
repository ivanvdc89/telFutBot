<?php

/**
 * Les peces de la configuració del jugador que no necessiten ni base de dades
 * ni Telegram.
 *
 * Són aquí i no dins de l'acció pel mateix motiu que actionsMenuRows(): el que
 * veu el jugador i el que s'accepta com a nom es pot provar solt, i una
 * validació fluixa es nota abans de desar res.
 */

/**
 * Longitud màxima del nom, la de players.name (VARCHAR(100)).
 */
const PLAYER_NAME_MAX_LENGTH = 100;

/**
 * Noms reservats perquè xoquen amb un subcomandament del bot.
 *
 * /data player all vol dir "tots els jugadors": un jugador que es digués "all"
 * tindria un botó que no el trobaria mai. Es compara sense distingir
 * majúscules.
 */
const RESERVED_PLAYER_NAMES = ['all'];

/**
 * El teclat de /configuració.
 *
 * /nom sense argument demana el nom amb ForceReply: el jugador només ha
 * d'escriure'l, sense cap comandament.
 *
 * @return array Files de botons.
 */
function settingsMenuRows(): array
{
    return [['/nom', '/inici']];
}

/**
 * El text que demana el nom.
 *
 * És una constant perquè el webhook ha de reconéixer la resposta: quan el
 * jugador contesta a un missatge que porta este text, el que escriu és el nom.
 */
const NAME_PROMPT = "Escriu el teu nom i envia'l:";

/**
 * Si el text d'un missatge respost és una petició de nom.
 */
function isNamePrompt(string $text): bool
{
    return str_contains($text, NAME_PROMPT);
}

/**
 * Els arguments de /nom per a un missatge que respon a una petició de nom.
 *
 * El nom pot tindre espais, així que el text sencer es parteix com ho faria el
 * comandament escrit a mà.
 *
 * @return array<int, string>|null Null si el missatge respost no demanava el nom.
 */
function nameReplyArgs(string $text, string $repliedText): ?array
{
    if ($repliedText === '' || !isNamePrompt($repliedText)) {
        return null;
    }

    // Un comandament escrit com a resposta no és cap nom: que el router el
    // tracte com el que és.
    if (str_starts_with($text, '/')) {
        return null;
    }

    return array_merge(['/nom'], explode(' ', $text));
}

/**
 * El text de /configuració amb l'estat del nom.
 *
 * @param string|null $currentName Nom actual, o null si encara no en té.
 */
function settingsText(?string $currentName): string
{
    $text = "Configuració\n\n";

    $text .= ($currentName === null || $currentName === '')
        ? "Encara no tens nom. El fan servir els anuncis i la llista de jugadors.\n"
        : "El teu nom: " . $currentName . "\n";

    return $text . "\nPer canviar-lo, toca \"Canviar el nom\".";
}

/**
 * Nom normalitzat: sense espais de més ni salts de línia.
 *
 * Els noms poden tenir espais ("Ivan Arrachina"), i Telegram envia el que
 * l'usuari escriu: aquí es converteix qualsevol tirada d'espais, tabuladors o
 * salts en un sol espai, i es treuen els de les puntes.
 */
function normalizePlayerName(string $rawName): string
{
    return trim(preg_replace('/\s+/u', ' ', $rawName));
}

/**
 * Motiu pel qual el nom no val, o null si és correcte.
 *
 * No mira si el nom ja és d'un altre jugador: això necessita la base de dades,
 * i ho comprova SettingsAction.
 */
function playerNameProblem(string $name): ?string
{
    if ($name === '') {
        return "El nom no pot estar buit.";
    }

    if (mb_strlen($name) > PLAYER_NAME_MAX_LENGTH) {
        return "El nom no pot passar de " . PLAYER_NAME_MAX_LENGTH . " caràcters.";
    }

    if (str_starts_with($name, '/')) {
        return "El nom no pot començar amb /.";
    }

    if (in_array(mb_strtolower($name), RESERVED_PLAYER_NAMES, true)) {
        return "El nom \"" . $name . "\" està reservat.";
    }

    return null;
}
