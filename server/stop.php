<?php
declare(strict_types=1);

/**
 * Detiene el servidor portable de Moodle de forma ordenada.
 */

require __DIR__ . '/common.php';

$p = mp_paths();
mp_envi($p);

$stopfile = $p['state'] . '/STOP.request';
$pidHttp  = $p['state'] . '/php_server.pid';
$pidDb    = $p['state'] . '/mariadb.pid';
$pidOrch  = $p['state'] . '/orchestrator.pid';

function mp_force_kill(int $pid): void {
    if (PHP_OS_FAMILY === 'Windows') {
        @shell_exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
        return;
    }
    @posix_kill($pid, 15);
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && mp_alive($pid)) {
        usleep(200000);
    }
    if (mp_alive($pid)) {
        @posix_kill($pid, 9);
    }
}

$orch = mp_readpid($pidOrch);

if ($orch !== null && mp_alive($orch)) {
    mp_log("Pidiendo parada ordenada al orquestador (PID {$orch})...");
    @file_put_contents($stopfile, '1');
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline && mp_alive($orch)) {
        usleep(400000);
    }
    if (mp_alive($orch)) {
        mp_log('El orquestador no respondió: forzando parada...');
        mp_force_kill($orch);
        usleep(500000);
    }
} else {
    mp_log('El orquestador no está en ejecución.');
}
@unlink($pidOrch);
@unlink($stopfile);

// Limpieza de restos (por si el orquestador murió antes de cerrarlos).
$dbport = (int)(getenv('DB_PORT') ?: 3307);
$dbpid = mp_readpid($pidDb);
if (mp_alive($dbpid)) {
    mp_log('Cerrando MariaDB...');
    $cmd = [$p['mdbadmin'], '-h127.0.0.1', '-P' . $dbport, '-u', 'root', 'shutdown'];
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
        while (microtime(true) < $deadline && mp_alive($dbpid)) {
            usleep(300000);
        }
        proc_close($proc);
    }
    if (mp_alive($dbpid)) {
        mp_force_kill($dbpid);
    }
}
@unlink($pidDb);

$hpid = mp_readpid($pidHttp);
if (mp_alive($hpid)) {
    mp_log('Deteniendo servidor HTTP...');
    mp_force_kill($hpid);
}
@unlink($pidHttp);

mp_log('Listo: todos los servicios detenidos.');
