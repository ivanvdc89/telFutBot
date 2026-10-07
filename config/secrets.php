<?php
/**
 * Accés als secrets d'aquest servidor.
 *
 * ELS VALORS NO SÓN AL REPOSITORI. Viuen a config/secrets.values.php, que està
 * al .gitignore. Com que git no toca mai els fitxers que no trackeja, els
 * secrets sobreviuen a cada git pull sense executar cap script.
 *
 * Per posar-ho en marxa en un servidor nou:
 *
 *     cp config/secrets.values.example.php config/secrets.values.php
 *     # i omple els valors
 *
 * Un cop fet això, desplegar és només git pull.
 */

/**
 * Retorna un secret de config/secrets.values.php.
 *
 * @param string $key        Claus definides al fitxer de valors.
 * @param bool   $allowEmpty Alguns valors poden ser legítimament buits, com una
 *                           contrasenya de base de dades sense contrasenya.
 *
 * @throws RuntimeException Si el fitxer de valors no hi és, si no retorna un
 *                          array, o si la clau falta o és buida quan no es permet.
 */
function secret(string $key, bool $allowEmpty = false): string
{
    static $values = null;

    if ($values === null) {
        $path = __DIR__ . '/secrets.values.php';

        if (!is_file($path)) {
            throw new RuntimeException(
                "Falten els secrets d'aquest servidor: copia config/secrets.values.example.php "
                . "a config/secrets.values.php i omple'l."
            );
        }

        $values = require $path;

        if (!is_array($values)) {
            throw new RuntimeException("config/secrets.values.php ha de retornar un array.");
        }
    }

    if (!array_key_exists($key, $values)) {
        throw new RuntimeException("El secret '$key' no està definit a config/secrets.values.php.");
    }

    $value = (string) $values[$key];

    if ($value === '' && !$allowEmpty) {
        throw new RuntimeException("El secret '$key' és buit a config/secrets.values.php.");
    }

    return $value;
}
