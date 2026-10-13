<?php
declare(strict_types=1);

/**
 * Utilidades comunes del servidor portable de Moodle.
 */

function mp_root(): string {
    return dirname(__DIR__);
}

/**
 * Rutas según plataforma.
 */
function mp_paths(): array {
    $root = mp_root();
    $win = PHP_OS_FAMILY === 'Windows';
    $phpdir = $root . ($win ? '/runtime/windows/php' : '/runtime/linux/php');
    $mdbdir = $root . ($win ? '/runtime/windows/mariadb' : '/runtime/linux/mariadb');
    $bin = $mdbdir . '/bin';

    return [
        'root'      => $root,
        'win'       => $win,
        'phpdir'    => $phpdir,
        'php'       => $phpdir . ($win ? '/php.exe' : '/php'),
        'mdbdir'    => $mdbdir,
        'mariadbd'  => $bin . ($win ? '/mariadbd.exe' : '/mariadbd'),
        'installdb' => $win ? $bin . '/mariadb-install-db.exe' : $mdbdir . '/scripts/mariadb-install-db',
        'mdbcli'    => $bin . ($win ? '/mariadb.exe' : '/mariadb'),
        'mdbadmin'  => $bin . ($win ? '/mariadb-admin.exe' : '/mariadb-admin'),
        'datadir'   => $root . '/data/mariadb',
        'libdir'    => $root . '/runtime/linux/lib',
        'logs'      => $root . '/logs',
        'state'     => $root . '/state',
        'moodledata'=> $root . '/moodledata',
        'cfg'       => $root . '/moodle/config.php',
        'docroot'   => $root . '/moodle/public',
        'cron'      => $root . '/moodle/admin/cli/cron.php',
        'installcli'=> $root . '/moodle/admin/cli/install.php',
    ];
}

/**
 * Entorno del proceso: LD_LIBRARY_PATH (Linux), PHPRC, PATH (Windows),
 * directorios de trabajo e ini de Windows con extension_dir absoluto.
 */
function mp_envi(array $p): void {
    foreach ([$p['logs'], $p['state'], $p['moodledata']] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0777, true);
        }
    }

    if (!$p['win']) {
        $ld = getenv('LD_LIBRARY_PATH');
        putenv('LD_LIBRARY_PATH=' . $p['libdir'] . ($ld !== false && $ld !== '' ? ':' . $ld : ''));
    } else {
        $path = getenv('PATH');
        putenv('PATH=' . $p['phpdir'] . ';' . $p['mdbdir'] . '\bin;' . $p['mdbdir'] . '\lib;'
            . ($path !== false ? $path : ''));
    }

    if (getenv('PHPRC') === false || getenv('PHPRC') === '') {
        putenv('PHPRC=' . $p['phpdir']);
    }

    if ($p['win']) {
        $ini = $p['phpdir'] . '/php.ini';
        if (is_file($ini)) {
            $s = (string)file_get_contents($ini);
            $extdir = str_replace('/', '\\', $p['phpdir'] . '/ext');
            $new = preg_replace('/^extension_dir\s*=\s*".*"$/m',
                'extension_dir = "' . addslashes($extdir) . '"', $s, 1);
            if (is_string($new) && $new !== $s) {
                @file_put_contents($ini, $new);
            }
        }
    }
}

function mp_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    $p = mp_paths();
    @file_put_contents($p['logs'] . '/server.log', $line . "\n", FILE_APPEND);
}

function mp_alive(?int $pid): bool {
    if ($pid === null || $pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
        return is_string($out) && (bool)preg_match('/\s' . preg_quote((string)$pid, '/') . '\s/', $out);
    }
    return @posix_kill($pid, 0);
}

function mp_readpid(string $file): ?int {
    if (!is_file($file)) {
        return null;
    }
    $v = trim((string)file_get_contents($file));
    return ctype_digit($v) ? (int)$v : null;
}

/**
 * Ejecuta SQL en MariaDB via el cliente CLI (no requiere extensión mysqli).
 * Devuelve [exit_code, salida].
 */
function mp_sql(array $p, string $sql, int $dbport): array {
    $cmd = [
        $p['mdbcli'], '-h127.0.0.1', '-P' . $dbport, '-u', 'root',
        '--batch', '--skip-column-names', '--raw', '-e', $sql,
    ];
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, $p['root']);
    if (!is_resource($proc)) {
        return [1, 'no se pudo ejecutar el cliente MariaDB'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + 30;
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            $out .= "\n(timeout)";
            break;
        }
        usleep(100000);
    }
    $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $st = $st ?? null;
    if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) {
        $code = $st['exitcode'];
    }
    return [$code, trim($out)];
}

/**
 * Espera a que MariaDB responda por TCP.
 */
function mp_wait_db(array $p, int $dbport, int $timeout = 90): bool {
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        [$code] = mp_sql($p, 'SELECT 1;', $dbport);
        if ($code === 0) {
            return true;
        }
        usleep(500000);
    }
    return false;
}

/**
 * Espera a que un puerto acepte conexiones.
 */
function mp_wait_port(string $host, int $port, int $timeout = 60): bool {
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return true;
        }
        usleep(300000);
    }
    return false;
}

function mp_terminate($proc, int $grace = 3): void {
    if (!is_resource($proc)) {
        return;
    }
    $st = proc_get_status($proc);
    if (!$st['running']) {
        proc_close($proc);
        return;
    }
    proc_terminate($proc);
    $deadline = microtime(true) + $grace;
    while (microtime(true) < $deadline) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        usleep(200000);
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, 9);
    }
    proc_close($proc);
}
