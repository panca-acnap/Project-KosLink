<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/layout.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'koslink_db'; // Pastikan nama database ini sama dengan yang kamu buat di phpMyAdmin


try {
    $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name, 3306);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $ex) {
    http_response_code(500);
    exit('Koneksi database gagal: ' . $ex->getMessage());
}

function q(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, string $types = '', array $params = []): array
{
    return q($sql, $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
}

function db_one(string $sql, string $types = '', array $params = []): ?array
{
    return db_all($sql, $types, $params)[0] ?? null;
}

function db_exec(string $sql, string $types = '', array $params = []): int
{
    $stmt = q($sql, $types, $params);
    return $stmt->affected_rows;
}

function sync_harga_mulai(int $id_properti): void
{
    db_exec(
        "UPDATE properti_kos SET harga_mulai = COALESCE((SELECT MIN(harga_bulan) FROM kamar WHERE id_properti = ?), 0) WHERE id_properti = ?",
        'ii',
        [$id_properti, $id_properti]
    );
}

function expire_pending_transactions(): void
{
    global $conn;
    $conn->begin_transaction();
    try {
        $rows = db_all("SELECT id_transaksi, id_kamar FROM transaksi
                        WHERE status_pembayaran = 'pending' AND tanggal_transaksi < (NOW() - INTERVAL 24 HOUR) FOR UPDATE");
        foreach ($rows as $r) {
            if (db_exec("UPDATE transaksi SET status_pembayaran='expire' WHERE id_transaksi = ? AND status_pembayaran='pending'", 's', [$r['id_transaksi']]) > 0) {
                db_exec("UPDATE kamar SET sisa_kamar = sisa_kamar + 1 WHERE id_kamar = ?", 'i', [$r['id_kamar']]);
            }
        }
        $conn->commit();
    } catch (Throwable $t) {
        $conn->rollback();
        error_log('expire_pending_transactions: ' . $t->getMessage());
    }
}