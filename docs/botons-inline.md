# Els botons del bot

Fins a la fase 0, tots els menús eren **teclats de resposta** amb el comandament
escrit: el jugador veia `/dobleORes pot CHL_1` i havia d'entendre que allò era
un botó. Ara són **botons inline** amb un text humà, i el comandament viatja dins
del `callback_data`.

Els comandaments escrits continuen funcionant: és el camí de reserva per als
jugadors que tenen un teclat vell a la pantalla o que els prefereixen.

## El camí d'una petició

    missatge de text ──> basic.php ──> Dispatcher ──> Acció
    botó polsat ──────> basic.php ──> CallbackRouter ──> Dispatcher ──> Acció

- **`basic.php`** només decidix qui executa la petició.
- **`actions/Dispatcher.php`** té el router de comandaments que abans vivia a
  `basic.php`, incloent-hi `/inici`, `/accions`, `/equips` i `/add`, que ara són
  accions com les altres.
- **`actions/CallbackRouter.php`** convertix el `callback_data` d'un botó en els
  arguments del comandament de sempre.

## Els dos formats de callback_data

Telegram limita `callback_data` a **64 bytes**. Els noms d'equip hi caben de
sobra (el més llarg de la temporada fa 17 caràcters), però un nom de jugador pot
fer 100. Per això hi ha dos formats:

| Format | Exemple | Què porta |
| --- | --- | --- |
| `c:<comandament>` | `c:/dobleORes pot CHL_1` | El comandament sencer |
| `dp:<id>` | `dp:7`, `dp:all` | L'id d'un jugador de `/data players` |

`config/menus.php` és qui construïx i desxifra estos valors, i
`commandCallbackData()` avisa si un comandament no hi cap.

## Com es fa un teclat

Les accions continuen construint files de comandaments, com abans:

```php
$rows = [];
$row  = [];

foreach ($teams as $team) {
    $row[] = '/add ' . $team['name'];

    if (count($row) === 3) {
        $rows[] = $row;
        $row    = [];
    }
}

$this->telegram->sendMessage(
    $this->chatId,
    "Equips:",
    false,
    null,
    null,
    InlineMenu::fromCommands($rows)
);
```

`InlineMenu::fromCommands()` convertix cada comandament en un botó amb
`menuLabel()` i `commandCallbackData()`. Si la llista pot quedar buida, es fa
servir `InlineMenu::optional($rows)`: Telegram no vol un teclat inline buit,
mentre que un teclat de resposta buit sí que valia.

Per a un botó que no és un comandament sencer (els jugadors de `/data players`)
hi ha `InlineMenu::button($text, $callbackData)` i `InlineMenu::make($rows)`.

## Com es canvia un text de botó

Els textos són a `config/menus.php`:

- `MENU_LABELS` per als comandaments sense arguments.
- Els prefixos de `menuLabel()` per als comandaments amb arguments
  (`/add X`, `/out X`, `/data pot X`, `/kos escut X`...).

Una etiqueta no ha de començar mai per `/`. `tests/menusTest.php` ho comprova,
igual que la llargada del `callback_data`.

## Què passa després de polsar un botó

`actions/CallbackBot.php` és un `BotApi` que s'encarrega de dos detalls:

1. **Lleva els botons del missatge polsat.** Si es quedaren, es podria tornar a
   enviar un vot des d'un menú vell.
2. **No deixa el jugador sense camí.** Si l'últim missatge que envia l'acció no
   porta botons (un error, una confirmació, una dada), li n'afig dos:
   «Accions» i «Inici».

`ReplyKeyboardRemove` no conta com un menú: és el que lleva el teclat de resposta
vell, i si el missatge no rep botons després, `finish()` n'hi posa.

## El nom del jugador

`/nom` sense argument ja no mostra el text «envia /nom ElTeuNom»: envia una
petició amb `ForceReply`. Quan el jugador respon a eixe missatge, `basic.php`
reconeix la resposta (`nameReplyArgs()`) i la convertix en `/nom <nom>`. Si
s'equivoca, l'error torna a portar la petició per a tornar-ho a provar.

## Desplegar

No hi ha canvis de base de dades ni cal tornar a registrar el webhook. Els
`callback_query` arriben pel mateix webhook. N'hi ha prou amb pujar el codi
(`git pull`).

Els jugadors que tinguen a la pantalla un teclat de resposta vell el perdran la
primera volta que toquen `/inici` o `/start`: eixe missatge porta
`ReplyKeyboardRemove` i, aprofitant el mateix missatge, s'hi posa el menú nou.

## Proves

```sh
php tests/menusTest.php       # etiquetes, callback_data i router de botons
php tests/callbackBotTest.php # què passa després de polsar un botó
php tests/dispatcherTest.php  # /inici, /accions, /equips i /add
php tests/settingsActionTest.php
```

I, com sempre, la resta de `tests/*Test.php`.
