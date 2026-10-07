<?php
/**
 * Aplica les substitucions pendents de la jornada en curs.
 *
 * Només toca la base de dades: canvia els equips a player_teams i marca les
 * substitucions com a executades. NO envia res a Telegram; d'això se n'encarrega
 * publishSubstitutions.php.
 *
 *     php applySubstitutions.php
 *
 * Aquesta separació existeix perquè l'anunci sigui reintentable: si l'enviament
 * a Telegram falla, es torna a executar el publicador i prou, sense haver de
 * tornar a aplicar els canvis ni arriscar-se a perdre'ls.
 *
 * L'ordre dels passos de la jornada és:
 *     applySubstitutions  ->  calculateMatchDayPoints  ->  publishSubstitutions
 *                                                      ->  publishResults
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/matchDay.php");
require_once("models/team.php");
require_once("models/substitution.php");

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$teamsRepo        = new Team();
$substitutionRepo = new Substitution();

$matchDay = currentMatchDay();

$pending = $substitutionRepo->getPendingSubstitutionsByMatchDay($matchDay);

if (count($pending) === 0) {
    echo "Cap substitució pendent per a la jornada $matchDay.\n";
    exit;
}

$applied = 0;
$substitutionRepo->beginTransaction();

try {
    foreach ($pending as $substitution) {
        $playerId  = (int) $substitution['player_id'];
        $oldTeamId = (int) $substitution['old_team_id'];
        $newTeamId = (int) $substitution['new_team_id'];

        // Primer s'aplica el canvi i només després es marca com a executada. Si
        // l'actualització falla, la fila ha de quedar pendent per reintentar-la;
        // marcar-la abans faria perdre el canvi en silenci.
        if ($oldTeamId === 0) {
            $result = $teamsRepo->addPlayerTeam($playerId, $newTeamId);
        } else {
            $result = $teamsRepo->changePlayerTeam($playerId, $oldTeamId, $newTeamId);
        }

        if ($result === false) {
            throw new RuntimeException(
                "Error de base de dades aplicant la substitució " . $substitution['id']
            );
        }

        if ($result === 0) {
            throw new RuntimeException(
                "La substitució " . $substitution['id'] . " no ha canviat cap fila: "
                . "el jugador $playerId no té l'equip $oldTeamId"
            );
        }

        $substitutionRepo->markSubstitutionAsExecuted((int) $substitution['id']);
        $applied++;

        echo "- " . $substitution['competition'] . " (jugador $playerId): "
            . $teamsRepo->getTeamName($oldTeamId) . " -> " . $teamsRepo->getTeamName($newTeamId) . "\n";
    }

    $substitutionRepo->commit();
} catch (Throwable $e) {
    $substitutionRepo->rollBack();

    fwrite(STDERR, "ERROR: no s'ha aplicat res, la transacció s'ha desfet.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "\nAplicades $applied substitucions de la jornada $matchDay.\n";
