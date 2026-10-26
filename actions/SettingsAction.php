<?php

require_once(__DIR__ . '/PlayerAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/settings.php');

use TelegramBot\Api\Types\ForceReply;

/**
 * Configuració del jugador: /configuració (estat) i /nom (posar-se el nom).
 *
 * Fins ara players.name es quedava a NULL perquè ningú no el demanava, i els
 * anuncis de publishActions.php i les llistes de /data players sortien amb el
 * nom buit. Amb /nom el pot escriure el mateix jugador, i /configuració li diu
 * quin té i com canviar-lo.
 *
 * Regles del nom, a part de les de config/settings.php (buit, llargada,
 * reservats):
 *   - Cap nom repetit: /data player <nom> ha de tornar un sol jugador, i
 *     getPlayerByName() en depèn. Es compara amb la mateixa col·lació de la
 *     base de dades, o sigui que "Ivan" i "ivan" són el mateix nom.
 *   - Es pot canviar sempre, sense límit d'intents: si el jugador ja porta el
 *     nom que demana no s'hi escriu res, només se li diu.
 *
 * El nom pot tenir espais ("Ivan Arrachina"). Per això /data player agafa tots
 * els mots que vénen després del subcomandament, no només el primer.
 */
class SettingsAction extends PlayerAction
{
    /**
     * Comandaments que gestiona esta acció.
     */
    private const COMMANDS = ['/configuració', '/settings', '/nom', '/name'];

    /**
     * Els que posen el nom; la resta mostren la configuració.
     */
    private const NAME_COMMANDS = ['/nom', '/name'];

    protected static function commands(): array
    {
        return self::COMMANDS;
    }

    protected function handle(int $playerId): void
    {
        if (in_array($this->command(), self::NAME_COMMANDS, true)) {
            $this->setName($playerId);

            return;
        }

        $this->showSettings($playerId);
    }

    /**
     * /configuració: quin nom té i com canviar-lo.
     */
    private function showSettings(int $playerId): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            settingsText($this->currentName($playerId)),
            false,
            null,
            null,
            InlineMenu::fromCommands(settingsMenuRows())
        );
    }

    /**
     * /nom <nom>: desa el nom del jugador.
     *
     * Sense argument demana el nom amb ForceReply, que fa que la resposta del
     * jugador vinga enllaçada al missatge del bot: el webhook la reconeix i la
     * converteix en /nom <nom>. Aixina no cal que ningú escriga un comandament.
     */
    private function setName(int $playerId): void
    {
        $name    = normalizePlayerName(implode(' ', array_slice($this->args, 1)));
        $current = $this->currentName($playerId);

        if ($name === '') {
            $this->askForName();

            return;
        }

        $problem = playerNameProblem($name);
        if ($problem !== null) {
            $this->sendNameError($problem);

            return;
        }

        if ($current === $name) {
            $this->telegram->sendMessage($this->chatId, "Ja et dius " . $name . ".");

            return;
        }

        if ($this->isNameTaken($name, $playerId)) {
            $this->sendNameError("Este nom ja és d'un altre jugador.");

            return;
        }

        if (!$this->playersRepo->updatePlayerName($playerId, $name)) {
            $this->telegram->sendMessage(
                $this->chatId,
                "ERROR, no s'ha pogut desar el nom. Torna-ho a provar."
            );

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Nom guardat: " . $name,
            false,
            null,
            null,
            InlineMenu::fromCommands([['/configuració', '/inici']])
        );
    }

    /**
     * Demana el nom amb ForceReply, perquè el jugador només haja d'escriure'l.
     */
    private function askForName(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            NAME_PROMPT,
            false,
            null,
            null,
            new ForceReply(true, null, 'El teu nom')
        );
    }

    /**
     * Error de nom i una nova petició, per no haver de tornar a buscar el botó.
     */
    private function sendNameError(string $reason): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "ERROR, " . $reason . "\n\n" . NAME_PROMPT,
            false,
            null,
            null,
            new ForceReply(true, null, 'El teu nom')
        );
    }

    /**
     * Nom actual del jugador, o null si encara no en té.
     */
    private function currentName(int $playerId): ?string
    {
        $player = $this->playersRepo->getPlayerById($playerId);

        if (!isset($player[0]['name']) || $player[0]['name'] === '') {
            return null;
        }

        return (string) $player[0]['name'];
    }

    /**
     * El nom ja és d'un altre jugador.
     */
    private function isNameTaken(string $name, int $playerId): bool
    {
        $matches = $this->playersRepo->getPlayerByName($name);

        if (!is_array($matches)) {
            return false;
        }

        foreach ($matches as $player) {
            if ((int) $player['id'] !== $playerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * /nom necessita una fila de players, i la crea /equips: aquí s'hi envida
     * el jugador en lloc de dir-li que no pot fer res.
     */
    protected function sendNotRegistered(): void
    {
        $keyboard = InlineMenu::fromCommands([['/equips', '/inici']]);

        $this->telegram->sendMessage(
            $this->chatId,
            "Encara no ets al joc. Toca \"Equips\" per començar; després podràs definir el teu nom.",
            false,
            null,
            null,
            $keyboard
        );
    }
}
