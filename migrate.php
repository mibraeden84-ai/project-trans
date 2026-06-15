<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/database.php';

$db = Database::getInstance();
$files = $argc > 1 ? array_slice($argv, 1) : [__DIR__ . '/api-schema.sql'];
$count = 0;
$errors = 0;

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $statement = '';
    $length = strlen($sql);
    $quote = null;
    $dollarTag = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($dollarTag !== null) {
            if (substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                $statement .= $dollarTag;
                $i += strlen($dollarTag) - 1;
                $dollarTag = null;
                continue;
            }
            $statement .= $char;
            continue;
        }

        if ($quote !== null) {
            $statement .= $char;
            if ($char === $quote) {
                if ($next === $quote) {
                    $statement .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            if ($i < $length) {
                $statement .= "\n";
            }
            continue;
        }

        if ($char === '/' && $next === '*') {
            $i += 2;
            while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i++;
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $statement .= $char;
            continue;
        }

        if ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/', $sql, $matches, 0, $i)) {
            $dollarTag = $matches[0];
            $statement .= $dollarTag;
            $i += strlen($dollarTag) - 1;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $statement = '';
            continue;
        }

        $statement .= $char;
    }

    $trimmed = trim($statement);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

foreach ($files as $file) {
    $path = __DIR__ . '/' . ltrim($file, '/');
    if (!file_exists($path)) {
        echo "[ERROR] File not found: $path\n";
        $errors++;
        continue;
    }
    echo "[INFO] Running migration: $file\n";
    $sql = file_get_contents($path);
    $statements = splitSqlStatements($sql);

    foreach ($statements as $stmt) {
        try {
            $db->query($stmt);
            $count++;
        } catch (Exception $e) {
            echo "[WARN] " . $e->getMessage() . "\n";
            $errors++;
        }
    }

}
echo "Migration complete: $count statements executed, $errors warnings\n";
