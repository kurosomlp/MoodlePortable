<?php
declare(strict_types=1);

/**
 * Orquestador portable de Moodle (multiplataforma).
 *
 * Arranca MariaDB portable, instala Moodle la primera vez (CLI, en español),
 * lanza el servidor HTTP de PHP y ejecuta el cron periódicamente.
 */

require __DIR__ . '/common.php';

$p = mp_paths();
mp_envi($p);

$bind      = (string)(getenv('MOODLE_BIND') !== false && getenv('MOODLE_BIND') !== '' ? getenv('MOODLE_BIND') : '127.0.0.1');
$port      = (int)(getenv('MOODLE_PORT') ?: 8080);
$dbport    = (int)(getenv('DB_PORT') ?: 3307);
$envWww    = getenv('MOODLE_WWWROOT');
$wwwroot   = rtrim($envWww !== false && $envWww !== '' ? $envWww : 'http://localhost:' . $port, '/');
$ob        = getenv('OPEN_BROWSER');
$openbrowser = $ob === false || $ob === '' ? true : !in_array(strtolower((string)$ob), ['0', 'false', 'no'], true);

$stopfile = $p['state'] . '/STOP.request';
$pidHttp  = $p['state'] . '/php_server.pid';
$pidDb    = $p['state'] . '/mariadb.pid';
$pidOrch  = $p['state'] . '/orchestrator.pid';

// --- Singleton -------------------------------------------------------------
$other = mp_readpid($pidOrch);
if ($other !== null && $other !== getmypid() && mp_alive($other)) {
    fwrite(STDERR, "Ya hay una instancia en ejecución (PID {$other}). Usa detener_moodle / Detener_Moodle.\n");
    exit(1);
}

// --- Credenciales persistentes ---------------------------------------------
$dbpassFile = $p['state'] . '/db_password';
$dbpass = is_file($dbpassFile) ? trim((string)file_get_contents($dbpassFile)) : '';
if ($dbpass === '') {
    $dbpass = bin2hex(random_bytes(8));
    @file_put_contents($dbpassFile, $dbpass . "\n");
}

@file_put_contents($pidOrch, (string)getmypid());
@unlink($stopfile);

// Estado de procesos vivos en este proceso.
$ctx = ['http' => null, 'db' => null, 'httppid' => null, 'dbpid' => null, 'done' => false];

// --- Manejo de señales (Linux) ---------------------------------------------
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $handler = function () use (&$ctx) {
        mp_log('Interrumpido (Ctrl+C): cerrando servicios...');
        mp_shutdown($p, $ctx, $stopfile);
        exit(130);
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
}

// --- Funciones --------------------------------------------------------------

function mp_http_host(string $bind): string {
    return ($bind === '0.0.0.0' || $bind === '::') ? '127.0.0.1' : $bind;
}

function mp_install_db(array $p, int $dbport): bool {
    if (!is_dir($p['datadir'] . '/mysql')) {
        mp_log('Inicializando datadir de MariaDB (primera vez)...');
        @mkdir($p['datadir'], 0777, true);
        if ($p['win']) {
            // El instalador de Windows (mysql_install_db.exe) solo acepta
            // --datadir/--port (rechaza --basedir, --auth-*, --skip-test-db).
            $cmd = [
                $p['installdb'],
                '--datadir=' . $p['datadir'],
                '--port=' . $dbport,
            ];
        } else {
            $cmd = [
                $p['installdb'],
                '--basedir=' . $p['mdbdir'],
                '--datadir=' . $p['datadir'],
                '--auth-root-authentication-method=normal',
                '--skip-test-db',
            ];
        }
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', $p['logs'] . '/install_db.log', 'a'],
            2 => ['file', $p['logs'] . '/install_db.log', 'a'],
        ];
        $proc = @proc_open($cmd, $spec, $pipes, $p['root']);
        if (!is_resource($proc)) {
            mp_log('ERROR: no se pudo ejecutar mariadb-install-db.');
            return false;
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $code = proc_close($proc);
        if ($code !== 0 || !is_dir($p['datadir'] . '/mysql')) {
            mp_log("ERROR: mariadb-install-db terminó con código {$code}. Ver logs/install_db.log");
            return false;
        }
        mp_log('Datadir inicializado.');
    }
    return true;
}

function mp_start_db(array $p, array &$ctx, int $dbport): bool {
    $pid = mp_readpid($p['state'] . '/mariadb.pid');
    if (mp_alive($pid)) {
        $ctx['dbpid'] = $pid;
        mp_log("MariaDB ya está en ejecución (PID {$pid}).");
        return true;
    }
    @unlink($p['state'] . '/mariadb.pid');

    if (!mp_install_db($p, $dbport)) {
        return false;
    }

    mp_log("Arrancando MariaDB en 127.0.0.1:{$dbport}...");
    $cmd = [
        $p['mariadbd'],
        '--basedir=' . $p['mdbdir'],
        '--datadir=' . $p['datadir'],
        '--bind-address=127.0.0.1',
        '--port=' . $dbport,
        '--pid-file=' . $p['state'] . '/mariadb.pid',
        '--log-error=' . $p['logs'] . '/mariadb.log',
        '--character-set-server=utf8mb4',
        '--collation-server=utf8mb4_unicode_ci',
        '--max-allowed-packet=64M',
        '--plugin-dir=' . $p['mdbdir'] . '/lib/plugin',
    ];
    if (!$p['win']) {
        $cmd[] = '--socket=' . $p['datadir'] . '/mariadb.sock';
    }
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $p['logs'] . '/mariadb.log', 'a'],
        2 => ['file', $p['logs'] . '/mariadb.log', 'a'],
    ];
    $ctx['db'] = @proc_open($cmd, $spec, $pipes, $p['root']);
    if (!is_resource($ctx['db'])) {
        mp_log('ERROR: no se pudo arrancar mariadbd.');
        return false;
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }

    if (!mp_wait_db($p, $dbport, 90)) {
        mp_log('ERROR: MariaDB no respondió. Ver logs/mariadb.log');
        return false;
    }
    $ctx['dbpid'] = mp_readpid($p['state'] . '/mariadb.pid');
    mp_log('MariaDB listo.');
    return true;
}

function mp_prepare_db(array $p, int $dbport, string $dbpass): bool {
    $sql = "CREATE DATABASE IF NOT EXISTS `moodle` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "DROP USER IF EXISTS 'moodle'@'localhost';\n"
        . "DROP USER IF EXISTS 'moodle'@'127.0.0.1';\n"
        . "CREATE USER 'moodle'@'localhost' IDENTIFIED BY '{$dbpass}';\n"
        . "CREATE USER 'moodle'@'127.0.0.1' IDENTIFIED BY '{$dbpass}';\n"
        . "GRANT ALL PRIVILEGES ON `moodle`.* TO 'moodle'@'localhost';\n"
        . "GRANT ALL PRIVILEGES ON `moodle`.* TO 'moodle'@'127.0.0.1';\n"
        . "FLUSH PRIVILEGES;\n";
    [$code, $out] = mp_sql($p, $sql, $dbport);
    if ($code !== 0) {
        mp_log('ERROR preparando base de datos: ' . $out);
        return false;
    }
    return true;
}

function mp_do_install(array $p, array $cfg): bool {
    mp_log('Instalando Moodle por primera vez (CLI, español)... esto puede tardar unos minutos.');

    $credFile = $p['state'] . '/admin_credentials.txt';
    $adminpass = bin2hex(random_bytes(12));

    $cmd = [
        $p['php'], $p['installcli'],
        '--non-interactive',
        '--agree-license',
        '--allow-unstable',
        '--wwwroot=' . $cfg['wwwroot'],
        '--dataroot=' . str_replace('\\', '/', $p['moodledata']),
        '--dbtype=mariadb',
        '--dbhost=127.0.0.1',
        '--dbport=' . $cfg['dbport'],
        '--dbname=moodle',
        '--dbuser=moodle',
        '--dbpass=' . $cfg['dbpass'],
        '--prefix=mdl_',
        '--fullname=Moodle Portable',
        '--shortname=Moodle',
        '--summary=Instalación local portable',
        '--lang=es',
        '--adminuser=admin',
        '--adminpass=' . $adminpass,
        '--adminemail=admin@localhost.local',
    ];

    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $p['logs'] . '/install.log', 'a'],
        2 => ['file', $p['logs'] . '/install.log', 'a'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, $p['root']);
    if (!is_resource($proc)) {
        mp_log('ERROR: no se pudo ejecutar la instalación CLI.');
        return false;
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }

    $deadline = microtime(true) + 900;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            mp_log('ERROR: la instalación superó 15 minutos. Ver logs/install.log');
            proc_close($proc);
            return false;
        }
        // Señal de parada durante la instalación.
        if (is_file($p['state'] . '/STOP.request')) {
            proc_terminate($proc);
            proc_close($proc);
            return false;
        }
        sleep(1);
    }
    proc_close($proc);

    if (!is_file($p['cfg'])) {
        mp_log('ERROR: la instalación no generó moodle/config.php. Ver logs/install.log');
        return false;
    }

    @file_put_contents($credFile,
        "URL: {$cfg['wwwroot']}\nUsuario admin: admin\nContraseña admin: {$adminpass}\n"
        . "Base de datos: moodle (usuario: moodle, contraseña: {$cfg['dbpass']}, 127.0.0.1:{$cfg['dbport']})\n");
    @file_put_contents($p['state'] . '/wwwroot.txt', $cfg['wwwroot'] . "\n");
    @file_put_contents($p['state'] . '/installed.flag', date('c') . "\n");
    mp_log('Instalación completada. Credenciales en state/admin_credentials.txt');
    return true;
}

/**
 * Ajusta wwwroot/dbport en config.php si cambiaron los puertos.
 */
function mp_patch_config(array $p, string $wwwroot, int $port, int $dbport, ?string $envWww): void {
    if (!is_file($p['cfg'])) {
        return;
    }
    $s = (string)file_get_contents($p['cfg']);
    $orig = $s;

    if (is_string($envWww) && $envWww !== '') {
        $s = preg_replace('/\$CFG->wwwroot\s*=\s*\'[^\']*\'/', "\$CFG->wwwroot = '" . $wwwroot . "'", $s);
    } else {
        // Solo reescribe si el wwwroot actual usa localhost con otro puerto.
        if (preg_match('/\$CFG->wwwroot\s*=\s*\'https?:\/\/localhost:(\d+)\'/', $s, $m)
                && (int)$m[1] !== $port) {
            $s = str_replace($m[0], "\$CFG->wwwroot = '" . $wwwroot . "'", $s);
        }
    }

    // dbport: solo si apunta a nuestra base local ($CFG->dbport o $CFG->dboptions).
    if (preg_match('/\$CFG->dbhost\s*=\s*\'(127\.0\.0\.1|localhost)\'/', $s)) {
        $s2 = preg_replace('/(\$CFG->dbport\s*=\s*)\d+/', '${1}' . $dbport, $s);
        $s2 = preg_replace('/(\'dbport\'\s*=>\s*)\d+/', '${1}' . $dbport, $s2);
        $s = $s2;
    }

    if ($s !== $orig) {
        @file_put_contents($p['cfg'], $s);
        @file_put_contents($p['state'] . '/wwwroot.txt', $wwwroot . "\n");
        mp_log('config.php actualizado (wwwroot/dbport).');
    }
}

function mp_start_http(array $p, array &$ctx, string $bind, int $port): bool {
    $pid = mp_readpid($p['state'] . '/php_server.pid');
    if (mp_alive($pid)) {
        $ctx['httppid'] = $pid;
        mp_log("Servidor HTTP ya está en ejecución (PID {$pid}).");
        return true;
    }
    @unlink($p['state'] . '/php_server.pid');

    mp_log("Arrancando servidor HTTP en {$bind}:{$port}...");
    $cmd = [$p['php'], '-S', $bind . ':' . $port, '-t', $p['docroot']];
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $p['logs'] . '/php.log', 'a'],
        2 => ['file', $p['logs'] . '/php.log', 'a'],
    ];
    // PHP_CLI_SERVER_WORKERS solo funciona en Linux.
    if (!$p['win']) {
        putenv('PHP_CLI_SERVER_WORKERS=4');
    }
    $ctx['http'] = @proc_open($cmd, $spec, $pipes, $p['docroot']);
    if (!is_resource($ctx['http'])) {
        mp_log('ERROR: no se pudo arrancar el servidor HTTP.');
        return false;
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }

    if (!mp_wait_port(mp_http_host($bind), $port, 60)) {
        mp_log('ERROR: el servidor HTTP no respondió. Ver logs/php.log');
        return false;
    }
    $st = proc_get_status($ctx['http']);
    $ctx['httppid'] = $st['pid'] ?: null;
    if ($ctx['httppid']) {
        @file_put_contents($p['state'] . '/php_server.pid', (string)$ctx['httppid']);
    }
    mp_log('Servidor HTTP listo.');
    return true;
}

function mp_run_cron(array $p): void {
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $p['logs'] . '/cron.log', 'a'],
        2 => ['file', $p['logs'] . '/cron.log', 'a'],
    ];
    $proc = @proc_open([$p['php'], $p['cron']], $spec, $pipes, $p['root']);
    if (!is_resource($proc)) {
        return;
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }
    $deadline = microtime(true) + 600;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline || is_file($p['state'] . '/STOP.request')) {
            proc_terminate($proc);
            break;
        }
        sleep(1);
    }
    proc_close($proc);
    mp_log('Cron ejecutado.');
}

function mp_open_browser(string $url, bool $win): void {
    $spec = [
        0 => ['pipe', 'r'],
        1 => $win ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
        2 => $win ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
    ];
    if ($win) {
        $proc = @proc_open(['cmd', '/c', 'start', '""', $url], $spec, $pipes);
    } else {
        $proc = @proc_open(['xdg-open', $url], $spec, $pipes);
    }
    if (is_resource($proc)) {
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        if (isset($pipes[1])) {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        proc_close($proc);
    }
}

function mp_shutdown(array $p, array &$ctx, string $stopfile): void {
    if (!empty($ctx['done'])) {
        return;
    }
    $ctx['done'] = true;

    if (is_resource($ctx['http'] ?? null)) {
        mp_log('Deteniendo servidor HTTP...');
        mp_terminate($ctx['http']);
        $ctx['http'] = null;
    }
    @unlink($p['state'] . '/php_server.pid');

    $dbpid = mp_readpid($p['state'] . '/mariadb.pid');
    if (mp_alive($dbpid)) {
        mp_log('Deteniendo MariaDB...');
        mp_shutdown_db($p);
    }
    @unlink($p['state'] . '/mariadb.pid');
    @unlink($stopfile);
    @unlink($p['state'] . '/orchestrator.pid');
    mp_log('Servicios detenidos.');
}

function mp_shutdown_db(array $p): void {
    $dbpid = mp_readpid($p['state'] . '/mariadb.pid');
    if (!mp_alive($dbpid)) {
        return;
    }
    $dbport = (int)(getenv('DB_PORT') ?: 3307);
    $cmd = [
        $p['mdbadmin'], '-h127.0.0.1', '-P' . $dbport, '-u', 'root', 'shutdown',
    ];
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $p['logs'] . '/mariadb.log', 'a'],
        2 => ['file', $p['logs'] . '/mariadb.log', 'a'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, $p['root']);
    if (is_resource($proc)) {
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (!mp_alive($dbpid)) {
                break;
            }
            usleep(300000);
        }
        proc_close($proc);
    }
    if (mp_alive($dbpid)) {
        // Último recurso: SIGTERM.
        if (PHP_OS_FAMILY !== 'Windows') {
            @posix_kill($dbpid, 15);
            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline && mp_alive($dbpid)) {
                usleep(300000);
            }
            if (mp_alive($dbpid)) {
                @posix_kill($dbpid, 9);
            }
        } else {
            @shell_exec('taskkill /PID ' . $dbpid . ' /F /T 2>NUL');
        }
    }
}

// --- Flujo principal --------------------------------------------------------

mp_log('Moodle Portable: iniciando...');

if (!mp_start_db($p, $ctx, $dbport)) {
    mp_log('No se pudo iniciar la base de datos. Abortando.');
    @unlink($pidOrch);
    exit(1);
}
if (!mp_prepare_db($p, $dbport, $dbpass)) {
    mp_log('No se pudo preparar la base de datos. Abortando.');
    mp_shutdown($p, $ctx, $stopfile);
    exit(1);
}

$installed = is_file($p['cfg']);

if (!$installed) {
    $ok = mp_do_install($p, [
        'wwwroot' => $wwwroot,
        'dbport'  => $dbport,
        'dbpass'  => $dbpass,
    ]);
    $installed = $ok && is_file($p['cfg']);
    if (!$installed) {
        mp_log('Instalación fallida: quedará disponible el instalador web en ' . $wwwroot . '/install.php');
    }
} else {
    mp_patch_config($p, $wwwroot, $port, $dbport, $envWww === false ? null : $envWww);
}

if (!mp_start_http($p, $ctx, $bind, $port)) {
    mp_log('No se pudo iniciar el servidor HTTP.');
    mp_shutdown($p, $ctx, $stopfile);
    exit(1);
}

$finalUrl = $wwwroot;
if ($openbrowser) {
    mp_open_browser($finalUrl, $p['win']);
}

echo "\n";
echo "========================================================\n";
echo "  Moodle Portable (5.3 LTS) - PHP " . PHP_VERSION . "\n";
echo "  URL:      {$finalUrl}\n";
echo "  Admin:    admin  (clave en state/admin_credentials.txt)\n";
echo "  Base:     MariaDB 127.0.0.1:{$dbport} (moodle)\n";
echo "  Datos:    moodledata/  |  Logs: logs/\n";
echo "  Detener:  detener_moodle.sh / Detener_Moodle.bat (o Ctrl+C)\n";
echo "========================================================\n\n";

// --- Bucle principal --------------------------------------------------------
$lastTick  = time();
$lastCron  = time();

while (true) {
    usleep(500000);

    if (is_file($stopfile)) {
        mp_log('Recibida orden de parada.');
        mp_shutdown($p, $ctx, $stopfile);
        exit(0);
    }

    if (time() - $lastTick >= 5) {
        $lastTick = time();
        // Vigilancia: relanzar lo que haya caído.
        $dbpid = mp_readpid($pidDb);
        if (!mp_alive($dbpid)) {
            mp_log('MariaDB no responde: reintentando...');
            if (!mp_start_db($p, $ctx, $dbport) || !mp_prepare_db($p, $dbport, $dbpass)) {
                mp_log('Reintento de base de datos fallido.');
            }
        }
        $hpid = mp_readpid($pidHttp);
        if (!mp_alive($hpid)) {
            mp_log('Servidor HTTP no responde: reintentando...');
            mp_start_http($p, $ctx, $bind, $port);
        }
    }

    if ($installed && is_file($p['cfg']) && time() - $lastCron >= 300) {
        $lastCron = time();
        mp_run_cron($p);
    }
}
