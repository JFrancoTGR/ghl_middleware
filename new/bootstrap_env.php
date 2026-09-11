<?php

declare (strict_types = 1);

/**
 * Bootstrap de variables de entorno para GHL Middleware.
 *
 * - Carga el archivo .env ubicado en la raíz del proyecto.
 * - No sobrescribe variables ya definidas en el entorno del sistema.
 * - No imprime ni registra valores sensibles.
 * - Proporciona env_required() y env_optional().
 */

$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

if (! is_file($envFile) || ! is_readable($envFile)) {
    throw new RuntimeException(
        'No se encontró un archivo .env legible en: ' . $envFile
    );
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES);

if ($lines === false) {
    throw new RuntimeException(
        'No fue posible leer el archivo .env.'
    );
}

foreach ($lines as $lineNumber => $line) {

    // Eliminar BOM UTF-8 si existe en la primera línea.
    if ($lineNumber === 0) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    $line = trim($line);

    // Ignorar líneas vacías y comentarios.
    if (
        $line === '' ||
        strpos($line, '#') === 0 ||
        strpos($line, ';') === 0
    ) {
        continue;
    }

    // Permitir sintaxis opcional:
    // export VARIABLE=valor
    if (strpos($line, 'export ') === 0) {
        $line = trim(substr($line, 7));
    }

    $separatorPosition = strpos($line, '=');

    if ($separatorPosition === false) {
        throw new RuntimeException(
            'Línea inválida en .env: ' . ($lineNumber + 1)
        );
    }

    $key   = trim(substr($line, 0, $separatorPosition));
    $value = trim(substr($line, $separatorPosition + 1));

    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
        throw new RuntimeException(
            'Nombre de variable inválido en .env, línea ' .
            ($lineNumber + 1)
        );
    }

    // Quitar comillas externas.
    $length = strlen($value);

    if ($length >= 2) {
        $first = $value[0];
        $last  = $value[$length - 1];

        if (
            ($first === '"' && $last === '"') ||
            ($first === "'" && $last === "'")
        ) {
            $value = substr($value, 1, -1);
        }
    }

    /**
     * Si la variable ya existe en el entorno del sistema,
     * esa configuración tiene prioridad sobre .env.
     */
    $existing = getenv($key);

    if ($existing === false) {
        putenv($key . '=' . $value);

        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    } else {
        $_ENV[$key]    = $existing;
        $_SERVER[$key] = $existing;
    }
}

/**
 * Obtiene una variable obligatoria.
 *
 * Falla inmediatamente si no existe o está vacía.
 */
function env_required(string $key): string
{
    $value = getenv($key);

    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }

    if ($value === false || trim((string) $value) === '') {
        throw new RuntimeException(
            'Variable de entorno obligatoria no configurada: ' . $key
        );
    }

    return (string) $value;
}

/**
 * Obtiene una variable opcional.
 */
function env_optional(
    string $key,
    ?string $default = null
): ?string {
    $value = getenv($key);

    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }

    if ($value === false || $value === null) {
        return $default;
    }

    return (string) $value;
}
