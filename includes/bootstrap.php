<?php
require_once __DIR__ . '/../config.php';
date_default_timezone_set(APP_TZ);

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';   // di balik proxy (Vercel, dll)
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (DB_SSL) {                                            // koneksi TLS ke database online
            $ca = DB_SSL_CA;
            if ($ca === '') foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $c) if (is_file($c)) { $ca = $c; break; }
            if ($ca !== '') $opt[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            else { $opt[PDO::MYSQL_ATTR_SSL_CAPATH] = '/etc/ssl/certs'; $opt[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; }
        }
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

/**
 * Sesi disimpan di database (bukan file) agar tetap hidup di hosting serverless
 * seperti Vercel, di mana tiap permintaan bisa dilayani server yang berbeda.
 */
class DbSession implements SessionHandlerInterface {
    private const SQL = 'CREATE TABLE IF NOT EXISTS sesi (id VARCHAR(128) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, diperbarui INT UNSIGNED NOT NULL, KEY idx_diperbarui (diperbarui)) ENGINE=InnoDB';
    private function run(callable $f) {
        try { return $f(); }
        catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;               // tabel belum ada -> buat otomatis
            db()->exec(self::SQL); return $f();
        }
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false {
        return $this->run(function () use ($id) {
            $st = db()->prepare('SELECT data FROM sesi WHERE id = ? AND diperbarui > ?');
            $st->execute([$id, time() - 8 * 3600]);
            $d = $st->fetchColumn();
            return $d === false ? '' : $d;
        });
    }
    public function write(string $id, string $data): bool {
        return $this->run(function () use ($id, $data) {
            db()->prepare('REPLACE INTO sesi (id, data, diperbarui) VALUES (?,?,?)')->execute([$id, $data, time()]);
            return true;
        });
    }
    public function destroy(string $id): bool {
        $this->run(fn() => db()->prepare('DELETE FROM sesi WHERE id = ?')->execute([$id]));
        return true;
    }
    public function gc(int $max): int|false {
        return $this->run(fn() => db()->prepare('DELETE FROM sesi WHERE diperbarui < ?')->execute([time() - 8 * 3600]) ? 1 : 0);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new DbSession(), true);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_name('presensi_ma');
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
