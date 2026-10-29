<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');

/**
 * Les normes del joc: /normes.
 *
 *   /normes            menú
 *   /normes bàsiques   les fotos de les regles i dels equips
 *   /normes accions    el text de cada norma d'acció, una per missatge
 *
 * És només text i imatges, i no depèn de cap jugador: no estén de PlayerAction.
 *
 * Els textos són el contingut del joc, no el codi: si una norma canvia, es
 * canvia aquí i prou (ACTION_RULES).
 */
class RulesAction extends CommandAction
{
    /**
     * Les fotos de les normes bàsiques, amb el peu que les acompanya.
     *
     * Els camins són relatius a l'arrel del projecte, com quan això era dins de
     * basic.php.
     */
    private const BASIC_RULES_PHOTOS = [
        ['files/rules.png', 'Regles del joc'],
        ['files/ch.png', 'Equips de la Champions'],
        ['files/el.png', 'Equips de la Europa League'],
        ['files/cl.png', 'Equips de la Conference League'],
    ];

    /**
     * Una norma per missatge, en l'ordre en què s'expliquen.
     */
    private const ACTION_RULES = [
        "Norma #malDia:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-L'objectiu és salvar aquells que vagen a tindre una jornada roina per a que no queden despenjats.
-S'ha de tindre en compte que en Conference les victòries donen 4 punts.
-En la competició que penseu que aneu a fer pocs punts s'active per a sumar més punts.
-Si fas 5 punts (o 6 en Conference) com a màxim => sumes 9 punts (o 12 en Conference).
-Si te passes de 5 (o 6 en Conference) => sumes només 2 punt.
-Només té efectes per a la jornada en la que s'active.

Exemple, s'active el #malDia a la Champions:
-Fent 5 o menys punts -> 9 punts
-Fent més de 5 punts, per exemple 7 -> 2 punt

Exemple, s'active el #malDia a la Conference League:
-Fent 6 o menys punts -> 12 punts
-Fent més de 6 punts, per exemple 9 -> 2 punt",

        "Norma #socElMillor:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-Entre tots els que s'activen la norma a cada competició, se li sumarà 3 (o 4 en COL) punts al jugador o jugadors que sumen més punts i a la resta se'ls restarà 3 (o 4 en COL) punts.
-Si només seu active 1 jugador, directament sume 3 (o 4 en COL) punts més.
-Només té efectes per a la jornada que s'active.

Exemple, si 3 s'activen el #socElMillor en Champions:
-Els que no s'han activat sumen els punts normals
-Si 3 s'han activat:
  -Jugador A: 10 punts (ha sigut el millor entre els que s'han activat) + 3 = 13 punts
  -Jugador B: 10 punts (ha sigut el millor entre els que s'han activat) + 3 = 13 punts
  -Jugador C: 8 punts (no ha fet el màxim) - 3 = 5 punts",

        "Norma #guanyarOMorir:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-Per cada victòria sumes un punt més als punts que han fet els 4 equips.
-Per cada empat o derrota restes un punt als punts que han fet els 4 equips.
-Només té efectes per a la jornada que s'active.

Exemples, si t'actives el #guanyarOMorir en Champions:
-Si guanyen 2, 1 empat i 1 derrota => 7 punts + 2 - 2 = 7 punts
-Si guanyen tots => 12 + 4 = 16 punts
-Si perden tots => 0 - 4 = -4 punts
-Si empten tots => 4 - 4 = 0 punts",

        "Norma #dobleORes:
-Hi ha una votació a repartir entre els equips de les 3 competicions.
-L'equip més votat de cada competició no sumarà cap punt, el segon més votat de cada competició sumarà doble (0, 2, 6 o 8 depenent del seu resultat).
-Tots els vots valen igual. Se pot donar més d'un vot a un equip. Si hi ha empat entre equips (encara que siga a 0 vots), l'ordre serà el de la classificació.
-Depenent dels punts en la general tenim més vots o menys. 1 vot per al primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4 vots.",

        "Norma #kosPais:
-Hi ha una votació per a boicotejar països: tots els equips dels 2 països més votats no sumaran punts independenment del resultat del seu partit.
-El boicot és sobre 2 països en total, no 1 per competició.
-Tots els vots de boicot valen igual. Es pot donar més d'un vot a un país. Si hi ha empat entre països, queda davant el país amb més punts sumats entre tots els seus equips a la classificació.
-Si no hi ha cap vot, queden boicotejats els 2 països amb més punts sumats entre tots els seus equips.
-Depenent de la nostra posició tenim més vots o menys. 1 vot per al primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4 vots.
-Per a protegir-se del boicot se usen escuts. Cada escut protegeix un dels teus equips del boicot i per a tu sumarà igual que sempre. Com a màxim 4 escuts.
-Amb només un escut l'equip ja està totalment protegit i sumarà punts. No es pot posar més d'un escut al mateix equip.",

        "Norma #kosPots:
-Hi ha una votació per a boicotejar pots: tots els equips dels 2 pots més votats no sumaran punts independenment del resultat del seu partit.
-El boicot és sobre 2 pots en total, no 1 per competició.
-Tots els vots de boicot valen igual. Es pot donar més d'un vot a un pot. Si hi ha empat entre pots, queda davant el pot amb més punts sumats entre tots els seus equips a la classificació.
-Si no hi ha cap vot, queden boicotejats els 2 pots amb més punts sumats entre tots els seus equips.
-Depenent de la nostra posició tenim més vots o menys. 1 vot per al primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4 vots.
-Per a protegir-se del boicot se usen escuts. Cada escut protegeix un dels teus equips del boicot i per a tu sumarà igual que sempre. Com a màxim 4 escuts.
-Amb només un escut l'equip ja està totalment protegit i sumarà punts. No es pot posar més d'un escut al mateix equip.",

        "Norma #kosAmbEscuts:
-Hi ha una votació per a boicotejar equips: els 4 equips més votats no sumaran punts independenment del resultat del seu partit.
-El boicot és sobre 4 equips en total, no 1 per competició.
-Tots els vots de boicot valen igual. Es pot donar més d'un vot a un equip. Si hi ha empat entre equips, queda davant l'equip que està millor en la classificació.
-Si no hi ha cap vot, queden boicotejats els 4 equips amb més punts a la classificació.
-Depenent de la nostra posició tenim més vots o menys. 1 vot per al primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4 vots.
-Per a protegir-se del boicot se usen escuts. Cada escut protegeix un dels teus equips del boicot i per a tu sumarà igual que sempre. Com a màxim 4 escuts.
-Amb només un escut l'equip ja està totalment protegit i sumarà punts. No es pot posar més d'un escut al mateix equip.",

        "Norma #millorFinal:
-Només té efectes per a les finals.
-Hi ha una votació per a donar més punts a la final de cada competició.
-La final més votada donarà 6 punts més al guanyador del partit (3 a cada finalista si hi ha empat al minut 90)
-La segona final més votada donarà 2 punts més al guanyador del partit (1 a cada finalista si hi ha empat al minut 90)
-La final menys votada restarà 2 punts al perdedor del partit (-1 a cada finalista si hi ha empat al minut 90)
-Depenent de la posició del jugador en la classificació, el vot té més importància. 1 per al primer i després cada 10 punts de diferència el jugador sume un vot més. Com a màxim 4",

        "Norma #segurQuePasse:
-Depenent de la nostra posició tenim més o menys punts disponibles. 1 punt el primer i després cada 10 punts de diferència el jugador té un punt més. Com a màxim 4.
-S'ha d'elegir quin dels nostres equips passaran de ronda. Si se classifique se sumaran, a part dels punts del partit, els punts que te toquen.
-Depenent del partit d'anada, si s'acerte que passarà, se sumaran més punts, 1 punt per cada gol de desventaja que porten del primer partit. 1 punt menys per cada gol de ventaja que porten del primer partit.

Per exemple, vas últim i te correspón 4 punts i marques que el equip1 (va perdre 1-0) passarà de ronda:
-Si no passe sumes els punts normal del partit (0, 1 o 3)
-Si passe, a més dels punts del partit, sumaràs els 4 punts que has apostat + 1 perqué tenie un gol de desventaja del primer partit.

Altre exemple, vas primer i te correspón 1 punt i marques que el equip2 (va empatar 1-1) passarà de ronda:
-Si no passe sumes els punts normal del partit (0, 1 o 3)
-Si passe, a més dels punts del partit, sumaràs el punt que s'ha apostat + 0 perqué no tenie cap gol de desventaja del primer partit.

Interese apostar per equips amb mal resultat, si han guanyat el primer partit per bastants gols pot ser no te compense ja que els gols de ventaja resten.",
    ];

    protected static function commands(): array
    {
        return ['/normes', '/rules'];
    }

    public function run(): void
    {
        if ($this->subCommand() === 'bàsiques') {
            $this->sendBasicRules();

            return;
        }

        if ($this->subCommand() === 'accions') {
            $this->sendActionRules();

            return;
        }

        // Sense subcomandament, o amb un que no existeix, el menú. Abans, un
        // subcomandament desconegut queia al missatge final de basic.php
        // ("Comença utilitzant els botons -> /inici"), que no deia res de les
        // normes.
        $this->showMenu();
    }

    /**
     * /normes: quines normes hi ha.
     */
    private function showMenu(): void
    {
        $keyboard = InlineMenu::fromCommands(
            [['/normes bàsiques', '/normes accions']]
        );

        $this->telegram->sendMessage(
            $this->chatId,
            "Normes disponibles:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * /normes bàsiques: les quatre imatges, en ordre.
     */
    private function sendBasicRules(): void
    {
        foreach (self::BASIC_RULES_PHOTOS as [$path, $caption]) {
            $this->telegram->sendPhoto($this->chatId, new CURLFile($path), $caption);
        }
    }

    /**
     * /normes accions: una norma per missatge.
     */
    private function sendActionRules(): void
    {
        foreach (self::ACTION_RULES as $rule) {
            $this->telegram->sendMessage($this->chatId, $rule);
        }
    }
}
