<?php
/**
 * Buku Tamu Digital - Perpustakaan SMPN 6 Keritang
 * Kios check-in, layar TV, dan rekap bulanan. Data disimpan ke tabel visitor_count milik SLiMS,
 * sehingga laporan pengunjung bawaan SLiMS tetap berfungsi.
 * PHP 7.4+ dengan PDO MySQL.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Jakarta');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$CFG = require __DIR__ . '/config.php';
$SLIMS = rtrim($CFG['folder_slims'], '/');
const COOKIE = 'bt_perangkat';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function json_out($a, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

/* ---------------- Database ---------------- */
function db()
{
    static $pdo;
    global $CFG, $SLIMS;
    if ($pdo) return $pdo;
    if (!empty($CFG['db_nama'])) {
        $c = [$CFG['db_host'] ?: 'localhost', $CFG['db_port'] ?: '3306', $CFG['db_nama'], $CFG['db_user'], $CFG['db_pass']];
    } elseif (is_file("$SLIMS/config/database.php")) {
        $d = include "$SLIMS/config/database.php";
        $n = $d['nodes'][$d['default_profile'] ?? 'SLiMS'] ?? reset($d['nodes']);
        $c = [$n['host'], $n['port'] ?: '3306', $n['database'], $n['username'], $n['password']];
    } elseif (is_file("$SLIMS/sysconfig.local.inc.php")) {
        $src = file_get_contents("$SLIMS/sysconfig.local.inc.php");
        $v = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $k)
            if (preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]/', $src, $m)) $v[$k] = $m[1];
        $c = [$v['DB_HOST'] ?? 'localhost', $v['DB_PORT'] ?? '3306', $v['DB_NAME'] ?? '', $v['DB_USERNAME'] ?? '', $v['DB_PASSWORD'] ?? ''];
    } else {
        throw new RuntimeException('Folder SLiMS tidak ditemukan. Periksa folder_slims di config.php.');
    }
    $pdo = new PDO("mysql:host={$c[0]};port={$c[1]};dbname={$c[2]};charset=utf8mb4", $c[3], $c[4], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
function q($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s; }

/** Tabel tambahan untuk menyimpan keperluan kunjungan (tidak mengubah tabel SLiMS). */
function ensure_purpose_table()
{
    db()->exec("CREATE TABLE IF NOT EXISTS bukutamu_keperluan (
        visitor_id INT NOT NULL PRIMARY KEY, keperluan VARCHAR(60) NOT NULL, KEY keperluan (keperluan)
      ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
}

function settings()
{
    static $mc;
    if ($mc) return $mc;
    $mc = ['front_header1_text' => 'Perpustakaan', 'city' => '', 'title' => 'Kepala Perpustakaan', 'officials' => '', 'officials_id' => '',
           'back_header2_text' => '', 'logo' => 'logo.png'];
    try {
        $v = db()->query("SELECT setting_value FROM setting WHERE setting_name = 'membercard_print_settings'")->fetchColumn();
        if ($v && is_array($a = @unserialize(stripslashes($v)))) $mc = array_merge($mc, $a);
    } catch (Throwable $e) {}
    return $mc;
}

/* ---------------- Perangkat terdaftar (PIN sekali, diingat 1 tahun) ---------------- */
function sign($data) { global $CFG; return hash_hmac('sha256', $data, $CFG['kunci_rahasia']); }
function device_ok()
{
    global $CFG;
    if (trim((string)($CFG['pin_petugas'] ?? '')) === '') return true; // PIN dimatikan: semua perangkat boleh
    $c = $_COOKIE[COOKIE] ?? '';
    $p = explode('.', $c);
    return count($p) === 3 && hash_equals(sign($p[0] . '.' . $p[1]), $p[2]) && (time() - (int)$p[1]) < 400 * 86400;
}
function device_register()
{
    $id = bin2hex(random_bytes(8));
    $val = $id . '.' . time();
    $val .= '.' . sign($val);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(COOKIE, $val, ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    $_COOKIE[COOKIE] = $val;
}
function api_token() { return sign('api|' . ($_COOKIE[COOKIE] ?? '')); }
function pin_attempt_ok()
{
    $f = sys_get_temp_dir() . '/bt_pin_' . md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . __DIR__);
    $now = time();
    $hits = is_file($f) ? array_filter(explode(',', (string)@file_get_contents($f)), function ($t) use ($now) { return $t && $now - (int)$t < 900; }) : [];
    return [$f, $hits, count($hits) < 5];
}

/* ---------------- Aset logo dari folder SLiMS ---------------- */
if (isset($_GET['aset'])) {
    $mc = settings();
    $f = basename($mc['logo'] ?? 'logo.png');
    $path = "$SLIMS/files/membercard/classic/images/$f";
    if (!is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

function first_name($n) { $p = preg_split('/\s+/', trim($n)); return $p[0] . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : ''); }
function mask_id($id) { $l = strlen($id); return $l <= 4 ? $id : substr($id, 0, 3) . str_repeat('•', max(1, $l - 5)) . substr($id, -2); }
function bulan($n) { return ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)$n]; }
function hari($ts) { return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int)date('w', $ts)]; }

/* ---------------- Simpan kunjungan ---------------- */
function save_visit($memberId, $name, $inst, $purpose)
{
    global $CFG;
    $now = date('Y-m-d H:i:s');
    $since = date('Y-m-d H:i:s', time() - 60 * (int)$CFG['jeda_menit']);
    $dup = $memberId !== null
        ? q('SELECT checkin_date FROM visitor_count WHERE member_id = ? AND checkin_date >= ? ORDER BY checkin_date DESC LIMIT 1', [$memberId, $since])->fetchColumn()
        : q('SELECT checkin_date FROM visitor_count WHERE member_id IS NULL AND member_name = ? AND checkin_date >= ? ORDER BY checkin_date DESC LIMIT 1', [$name, $since])->fetchColumn();
    if ($dup) return ['dobel' => true, 'jam' => date('H:i', strtotime($dup))];
    q('INSERT INTO visitor_count (member_id, member_name, institution, room_code, checkin_date) VALUES (?, ?, ?, NULL, ?)', [$memberId, $name, $inst, $now]);
    $vid = (int)db()->lastInsertId();
    if (!empty($CFG['tanya_keperluan']) && $purpose !== '' && in_array($purpose, $CFG['keperluan'], true)) {
        ensure_purpose_table();
        q('INSERT INTO bukutamu_keperluan (visitor_id, keperluan) VALUES (?, ?)', [$vid, $purpose]);
    }
    return ['dobel' => false, 'jam' => date('H:i')];
}

/* ---------------- API untuk kios ---------------- */
if (isset($_GET['api'])) {
    try {
        if (!device_ok()) json_out(['ok' => false, 'pesan' => 'Perangkat belum terdaftar.'], 403);
        if (!hash_equals(api_token(), (string)($_SERVER['HTTP_X_KIOS'] ?? ''))) json_out(['ok' => false, 'pesan' => 'Token tidak valid. Muat ulang halaman.'], 403);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        switch ($_GET['api']) {
            case 'cari':
                $s = trim((string)($in['q'] ?? ''));
                if (mb_strlen($s) < 3) json_out(['ok' => true, 'hasil' => []]);
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
                $rows = q("SELECT m.member_id, m.member_name, t.member_type_name FROM member m LEFT JOIN mst_member_type t ON t.member_type_id = m.member_type_id
                           WHERE (m.member_name LIKE ? OR m.member_id = ?) AND m.is_pending = 0 ORDER BY (m.member_id = ?) DESC, m.member_name LIMIT 8", [$like, $s, $s])->fetchAll();
                json_out(['ok' => true, 'hasil' => array_map(function ($r) {
                    return ['id' => $r['member_id'], 'nama' => $r['member_name'], 'tipe' => $r['member_type_name'] ?: 'Anggota', 'idm' => mask_id($r['member_id'])];
                }, $rows)]);
            case 'masuk':
                $id = trim((string)($in['id'] ?? ''));
                $m = q('SELECT m.member_id, m.member_name, m.inst_name, m.expire_date, t.member_type_name FROM member m LEFT JOIN mst_member_type t ON t.member_type_id = m.member_type_id WHERE m.member_id = ? AND m.is_pending = 0', [$id])->fetch();
                if (!$m) json_out(['ok' => false, 'pesan' => 'Nomor anggota tidak ditemukan. Coba ketik nama Anda.']);
                $r = save_visit($m['member_id'], $m['member_name'], $m['inst_name'] ?: null, (string)($in['keperluan'] ?? ''));
                $n = (int)q("SELECT COUNT(*) FROM visitor_count WHERE member_id = ? AND checkin_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$m['member_id']])->fetchColumn();
                $exp = $m['expire_date'] && $m['expire_date'] !== '0000-00-00' && $m['expire_date'] < date('Y-m-d');
                json_out(['ok' => true, 'dobel' => $r['dobel'], 'jam' => $r['jam'], 'nama' => $m['member_name'], 'tipe' => $m['member_type_name'] ?: 'Anggota', 'ke' => $n, 'kedaluwarsa' => $exp]);
            case 'tamu':
                $name = trim(preg_replace('/\s+/u', ' ', (string)($in['nama'] ?? '')));
                $inst = trim(preg_replace('/\s+/u', ' ', (string)($in['instansi'] ?? '')));
                if (mb_strlen($name) < 3) json_out(['ok' => false, 'pesan' => 'Nama minimal 3 huruf.']);
                $name = mb_substr($name, 0, 100);
                $r = save_visit(null, $name, $inst !== '' ? mb_substr($inst, 0, 100) : null, (string)($in['keperluan'] ?? ''));
                json_out(['ok' => true, 'dobel' => $r['dobel'], 'jam' => $r['jam'], 'nama' => $name, 'tipe' => 'Tamu', 'ke' => 0]);
            case 'layar':
                json_out(['ok' => true] + layar_data());
        }
        json_out(['ok' => false, 'pesan' => 'Permintaan tidak dikenal.'], 400);
    } catch (Throwable $e) {
        error_log('[bukutamu] ' . $e->getMessage());
        json_out(['ok' => false, 'pesan' => 'Terjadi gangguan. Silakan lapor ke petugas.'], 500);
    }
}

function layar_data()
{
    $one = function ($sql) { return (int)q($sql)->fetchColumn(); };
    $recent = q("SELECT member_name, IF(member_id IS NULL, 'Tamu', '') t, DATE_FORMAT(checkin_date, '%H:%i') jam FROM visitor_count WHERE checkin_date >= CURDATE() ORDER BY checkin_date DESC LIMIT 8")->fetchAll();
    $top = q("SELECT v.member_name, COUNT(*) n FROM visitor_count v WHERE v.member_id IS NOT NULL AND v.checkin_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY v.member_id, v.member_name ORDER BY n DESC, MAX(v.checkin_date) DESC LIMIT 5")->fetchAll();
    $books = q("SELECT title FROM biblio WHERE opac_hide = 0 ORDER BY input_date DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    return [
        'hari_ini' => $one("SELECT COUNT(*) FROM visitor_count WHERE checkin_date >= CURDATE()"),
        'minggu_ini' => $one("SELECT COUNT(*) FROM visitor_count WHERE YEARWEEK(checkin_date, 1) = YEARWEEK(CURDATE(), 1)"),
        'bulan_ini' => $one("SELECT COUNT(*) FROM visitor_count WHERE checkin_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'terbaru' => array_map(function ($r) { return ['nama' => $r['t'] ? $r['member_name'] : first_name($r['member_name']), 'tamu' => (bool)$r['t'], 'jam' => $r['jam']]; }, $recent),
        'teraktif' => array_map(function ($r) { return ['nama' => first_name($r['member_name']), 'n' => (int)$r['n']]; }, $top),
        'buku_baru' => $books,
        'jam' => date('H:i'), 'tanggal' => hari(time()) . ', ' . date('j') . ' ' . bulan(date('n')) . ' ' . date('Y'),
    ];
}

/* ---------------- Halaman ---------------- */
$page = $_GET['p'] ?? 'kios';
if (!in_array($page, ['kios', 'layar', 'rekap', 'keluar'], true)) $page = 'kios';
$pinErr = '';

// Login PIN
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pin'])) {
    list($f, $hits, $ok) = pin_attempt_ok();
    if (!$ok) {
        $pinErr = 'Terlalu banyak percobaan. Coba lagi 15 menit lagi.';
    } elseif (hash_equals((string)$CFG['pin_petugas'], trim((string)$_POST['pin']))) {
        @unlink($f);
        device_register();
        header('Location: ?p=' . urlencode($page));
        exit;
    } else {
        $hits[] = time();
        @file_put_contents($f, implode(',', $hits), LOCK_EX);
        $pinErr = 'PIN salah.';
    }
}
if ($page === 'keluar') {
    setcookie(COOKIE, '', time() - 3600, '/');
    header('Location: ./');
    exit;
}

$mc = settings();
$lib = $mc['front_header1_text'] ?? 'Perpustakaan';
$authed = device_ok();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Buku Tamu · <?php echo h($lib) ?></title>
<link rel="icon" href="?aset=logo">
<style>
@font-face { font-family: "Quicksand"; src: url('fonts/Quicksand-Regular.ttf') format('truetype'); font-weight: 400; }
@font-face { font-family: "Quicksand"; src: url('fonts/Quicksand-Bold.ttf') format('truetype'); font-weight: 700; }
:root { --navy:#2A2C84; --navy-2:#1B1D5E; --sky:#A0D9F6; --sky-soft:#EAF6FD; --gold:#F2C230; --green:#2E9E48; --ink:#1A1A2E; --muted:#5B6382; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
html, body { margin: 0; height: 100%; }
body { font-family: 'Quicksand', 'Segoe UI', sans-serif; color: var(--ink); background: var(--sky-soft); }
button, input { font: inherit; }
.top { background: linear-gradient(120deg, var(--navy), var(--navy-2)); color: #fff; padding: 14px 24px; display: flex; align-items: center; gap: 14px; position: relative; }
.top::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: linear-gradient(90deg, var(--gold) 0 70%, var(--green) 70%); }
.top img { width: 52px; height: 52px; object-fit: contain; }
.top .k { color: var(--gold); font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
.top .t { font-size: 22px; font-weight: 700; line-height: 1.15; }
.top .clock { margin-left: auto; text-align: right; }
.top .clock b { font-size: 28px; display: block; line-height: 1; }
.top .clock span { font-size: 13px; opacity: .85; }
.btn { border: 0; border-radius: 14px; padding: 14px 22px; font-weight: 700; font-size: 18px; cursor: pointer; background: var(--navy); color: #fff; }
.btn.gold { background: var(--gold); color: var(--navy-2); }
.btn.ghost { background: #fff; color: var(--navy); border: 2px solid #D5DCEA; }
.card { background: #fff; border-radius: 22px; box-shadow: 0 6px 30px rgba(27,29,94,.10); }
.small-links { position: fixed; right: 12px; bottom: 8px; font-size: 12px; color: #8a91a8; }
.small-links a { color: #8a91a8; margin-left: 10px; }

/* PIN */
.pinbox { max-width: 380px; margin: 8vh auto; padding: 28px; text-align: center; }
.pinbox input { width: 100%; font-size: 28px; letter-spacing: 8px; text-align: center; padding: 12px; border: 2px solid #D5DCEA; border-radius: 12px; margin: 14px 0; }
.err { color: #B3261E; font-weight: 700; }

/* KIOS */
.kios { max-width: 980px; margin: 0 auto; padding: 28px 20px 60px; }
.kios h1 { font-size: 40px; color: var(--navy); margin: 8px 0 4px; text-align: center; }
.kios .lead { text-align: center; font-size: 19px; color: var(--muted); margin: 0 0 22px; }
.scan { padding: 24px; }
.scan input { width: 100%; font-size: 30px; padding: 18px 22px; border: 3px solid var(--sky); border-radius: 16px; outline: none; }
.scan input:focus { border-color: var(--navy); }
.scan .hint { margin-top: 10px; color: var(--muted); font-size: 15px; display: flex; gap: 18px; flex-wrap: wrap; }
.results { margin-top: 14px; display: grid; gap: 10px; }
.res { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border: 2px solid #E3E8F2; border-radius: 14px; background: #fff; cursor: pointer; text-align: left; width: 100%; }
.res:hover, .res:focus { border-color: var(--navy); background: var(--sky-soft); outline: none; }
.res .nm { font-size: 22px; font-weight: 700; }
.res .mt { color: var(--muted); font-size: 15px; }
.pill { display: inline-block; background: var(--gold); color: var(--navy-2); font-weight: 700; font-size: 13px; padding: 2px 10px; border-radius: 10px; margin-right: 8px; }
.guest-row { text-align: center; margin-top: 22px; }
.overlay { position: fixed; inset: 0; background: rgba(27,29,94,.55); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 10; }
.overlay.show { display: flex; }
.modal { max-width: 640px; width: 100%; padding: 30px; text-align: center; }
.modal h2 { color: var(--navy); font-size: 28px; margin: 0 0 6px; }
.modal p { color: var(--muted); font-size: 18px; margin: 0 0 18px; }
.purposes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.purposes .btn { font-size: 19px; padding: 18px; }
.form-guest input { width: 100%; font-size: 22px; padding: 14px 16px; border: 2px solid #D5DCEA; border-radius: 12px; margin-bottom: 12px; }
.welcome { text-align: center; padding: 40px 30px; }
.welcome .emo { font-size: 70px; }
.welcome h2 { font-size: 40px; color: var(--navy); margin: 6px 0; }
.welcome .sub { font-size: 22px; color: var(--muted); }
.welcome .note { margin-top: 14px; font-size: 17px; background: #FFF4D6; border-radius: 10px; padding: 10px 14px; display: inline-block; }
.bar { height: 6px; background: #E3E8F2; border-radius: 4px; overflow: hidden; margin-top: 24px; }
.bar i { display: block; height: 100%; background: var(--gold); width: 100%; transition: width linear; }

/* LAYAR TV */
.tv { display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 22px; padding: 26px; height: calc(100vh - 84px); }
.tv .card { padding: 22px 26px; overflow: hidden; }
.tv h3 { margin: 0 0 14px; color: var(--navy); font-size: 22px; text-transform: uppercase; letter-spacing: 1px; }
.big { display: grid; gap: 16px; }
.stat { background: linear-gradient(120deg, var(--navy), var(--navy-2)); color: #fff; border-radius: 18px; padding: 18px 22px; }
.stat b { font-size: 64px; line-height: 1; display: block; }
.stat span { font-size: 18px; opacity: .9; }
.stat.gold { background: var(--gold); color: var(--navy-2); }
.stat.sky { background: var(--sky); color: var(--navy-2); }
.lst { list-style: none; margin: 0; padding: 0; }
.lst li { display: flex; align-items: center; justify-content: space-between; padding: 11px 0; border-bottom: 1px dashed #DDE3EE; font-size: 22px; }
.lst li .r { color: var(--muted); font-size: 18px; }
.rank { display: inline-flex; width: 34px; height: 34px; border-radius: 50%; align-items: center; justify-content: center; background: var(--sky-soft); color: var(--navy); font-weight: 700; margin-right: 12px; font-size: 18px; }
.rank.r1 { background: var(--gold); }
.books li { font-size: 19px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.empty { color: var(--muted); font-size: 18px; }

/* REKAP */
.rekap { max-width: 900px; margin: 0 auto; padding: 22px 16px 50px; }
.filter { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 16px 18px; margin-bottom: 18px; }
.filter select { font: inherit; font-size: 16px; padding: 10px 12px; border-radius: 10px; border: 2px solid #D5DCEA; }
.filter .btn { font-size: 16px; padding: 10px 18px; }
.paper { background: #fff; padding: 12mm 14mm 10mm; box-shadow: 0 4px 20px rgba(0,0,0,.08); font-size: 11px; color: #000; font-family: 'Segoe UI', Arial, sans-serif; }
.kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 8px; }
.kop img { width: 64px; height: 64px; object-fit: contain; }
.kop .c { flex: 1; text-align: center; }
.kop .c .a { font-size: 13px; font-weight: 700; }
.kop .c .b { font-size: 17px; font-weight: 700; }
.kop .c .d { font-size: 11px; }
.paper h2 { text-align: center; font-size: 14px; margin: 14px 0 2px; text-decoration: underline; }
.paper .per { text-align: center; margin-bottom: 12px; }
.sum { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 8px 0 10px; }
.sum div { border: 1px solid #999; padding: 6px 8px; text-align: center; }
.sum b { display: block; font-size: 18px; }
table.t { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.t th, table.t td { border: 1px solid #666; padding: 1.5px 5px; line-height: 1.3; }
table.t th { background: #E8EDF7; }
table.t td.n { text-align: center; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ttd { margin-top: 10px; break-inside: avoid; display: flex; justify-content: flex-end; }
.ttd div { text-align: center; width: 240px; }
.ttd .sp { height: 52px; }
h4 { margin: 8px 0 4px; font-size: 12.5px; }
@media print {
  @page { size: A4; margin: 0; }
  body { background: #fff; }
  .top, .filter, .small-links, .noprint { display: none !important; }
  .rekap { padding: 0; max-width: none; }
  .paper { box-shadow: none; }
}
</style>
</head>
<body>

<?php if (!$authed) : ?>
  <div class="top"><img src="?aset=logo" alt=""><div><div class="k">Buku Tamu Digital</div><div class="t"><?php echo h($lib) ?></div></div></div>
  <form class="card pinbox" method="post" action="?p=<?php echo h($page) ?>">
    <h2 style="color:var(--navy);margin:0">Daftarkan perangkat ini</h2>
    <p style="color:var(--muted)">Masukkan PIN petugas. Perangkat ini akan diingat, jadi PIN hanya perlu dimasukkan sekali.</p>
    <input type="password" name="pin" inputmode="numeric" autocomplete="off" autofocus required>
    <?php if ($pinErr) : ?><p class="err"><?php echo h($pinErr) ?></p><?php endif ?>
    <button class="btn" type="submit" style="width:100%">Masuk</button>
  </form>

<?php elseif ($page === 'kios') : ?>
  <div class="top">
    <img src="?aset=logo" alt="">
    <div><div class="k">Buku Tamu Digital</div><div class="t"><?php echo h($lib) ?></div></div>
    <div class="clock"><b id="clk"><?php echo date('H:i') ?></b><span><?php echo hari(time()) . ', ' . date('j') . ' ' . bulan(date('n')) . ' ' . date('Y') ?></span></div>
  </div>
  <div class="kios">
    <h1>Selamat Datang! 📚</h1>
    <p class="lead">Scan kartu anggota Anda, atau ketik nama lalu pilih nama Anda.</p>
    <div class="card scan">
      <input id="inp" type="text" autocomplete="off" placeholder="Scan kartu / ketik nama..." autofocus>
      <div class="hint"><span>🔎 Ketik minimal 3 huruf</span><span>📇 Scan barcode kartu anggota</span></div>
      <div class="results" id="res"></div>
    </div>
    <div class="guest-row"><button class="btn ghost" id="guestBtn">👤 Saya tamu / bukan anggota</button></div>
  </div>

  <div class="overlay" id="ovPurpose"><div class="card modal">
    <h2 id="pName">Halo!</h2><p>Apa keperluan Anda hari ini?</p>
    <div class="purposes" id="pList"></div>
    <p style="margin:16px 0 0"><button class="btn ghost" data-close>Batal</button></p>
  </div></div>

  <div class="overlay" id="ovGuest"><div class="card modal form-guest">
    <h2>Data Tamu</h2><p>Silakan isi nama dan asal instansi Anda.</p>
    <input id="gName" placeholder="Nama lengkap" maxlength="100">
    <input id="gInst" placeholder="Instansi / asal (mis. Orang tua siswa, Dinas Pendidikan)" maxlength="100">
    <p class="err" id="gErr"></p>
    <p style="margin:6px 0 0;display:flex;gap:10px;justify-content:center"><button class="btn ghost" data-close>Batal</button><button class="btn" id="gNext">Lanjut →</button></p>
  </div></div>

  <div class="overlay" id="ovWelcome"><div class="card modal welcome">
    <div class="emo" id="wEmo">👋</div><h2 id="wTitle"></h2><div class="sub" id="wSub"></div><div class="note" id="wNote" style="display:none"></div>
    <div class="bar"><i id="wBar"></i></div>
  </div></div>

  <div class="small-links noprint"><a href="?p=layar">Layar TV</a><a href="?p=rekap">Rekap</a><?php if (trim((string)$CFG['pin_petugas']) !== '') : ?><a href="?p=keluar" onclick="return confirm('Hapus pendaftaran perangkat ini?')">Lepas perangkat</a><?php endif ?></div>

  <script>
  const TOKEN = <?php echo json_encode(api_token()) ?>;
  const ASK = <?php echo json_encode(!empty($CFG['tanya_keperluan'])) ?>;
  const PURPOSES = <?php echo json_encode(array_values($CFG['keperluan']), JSON_UNESCAPED_UNICODE) ?>;
  const $ = s => document.querySelector(s);
  const inp = $('#inp'), res = $('#res');
  let pending = null, tmr = null;

  async function api(name, data) {
    const r = await fetch('?api=' + name, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Kios': TOKEN }, body: JSON.stringify(data) });
    return r.json().catch(() => ({ ok: false, pesan: 'Gangguan koneksi.' }));
  }
  function show(id) { document.querySelectorAll('.overlay').forEach(o => o.classList.remove('show')); if (id) $(id).classList.add('show'); }
  function reset() { show(null); inp.value = ''; res.innerHTML = ''; pending = null; inp.focus(); }
  document.querySelectorAll('[data-close]').forEach(b => b.onclick = reset);
  function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  // tampilkan pilihan keperluan
  $('#pList').innerHTML = PURPOSES.map(p => `<button class="btn ${p === 'Lainnya' ? 'ghost' : ''}" data-p="${esc(p)}">${esc(p)}</button>`).join('');
  $('#pList').onclick = e => { const b = e.target.closest('[data-p]'); if (b && pending) submit(b.dataset.p); };

  function choose(target) { pending = target; if (ASK) { $('#pName').textContent = 'Halo, ' + target.label + '!'; show('#ovPurpose'); } else submit(''); }

  async function submit(purpose) {
    const p = pending; if (!p) return;
    const r = p.type === 'tamu' ? await api('tamu', { nama: p.nama, instansi: p.instansi, keperluan: purpose })
                                : await api('masuk', { id: p.id, keperluan: purpose });
    welcome(r);
  }

  function welcome(r) {
    let ms = 4000;
    $('#wNote').style.display = 'none';
    if (!r.ok) { $('#wEmo').textContent = '⚠️'; $('#wTitle').textContent = 'Maaf'; $('#wSub').textContent = r.pesan || 'Terjadi kesalahan.'; ms = 3500; }
    else if (r.dobel) { $('#wEmo').textContent = '✅'; $('#wTitle').textContent = r.nama; $('#wSub').textContent = 'Anda sudah tercatat pukul ' + r.jam + '. Selamat membaca!'; }
    else {
      $('#wEmo').textContent = '👋'; $('#wTitle').textContent = 'Selamat datang, ' + r.nama + '!';
      $('#wSub').textContent = r.tipe === 'Tamu' ? 'Terima kasih telah berkunjung.' : (r.ke > 1 ? 'Ini kunjungan ke-' + r.ke + ' Anda bulan ini. Hebat! 🌟' : 'Kunjungan pertama Anda bulan ini. Selamat membaca!');
      if (r.kedaluwarsa) { $('#wNote').textContent = 'Masa keanggotaan Anda sudah habis. Silakan perpanjang di meja petugas.'; $('#wNote').style.display = 'inline-block'; ms = 6000; }
    }
    show('#ovWelcome');
    const bar = $('#wBar'); bar.style.transition = 'none'; bar.style.width = '100%';
    requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.transition = 'width ' + ms + 'ms linear'; bar.style.width = '0%'; }));
    setTimeout(reset, ms);
  }

  async function search() {
    const v = inp.value.trim();
    if (v.length < 3) { res.innerHTML = ''; return; }
    const r = await api('cari', { q: v });
    if (inp.value.trim() !== v) return;
    if (!r.ok) { res.innerHTML = `<div class="err">${esc(r.pesan || '')}</div>`; return; }
    res.innerHTML = r.hasil.length ? r.hasil.map(h => `<button class="res" data-id="${esc(h.id)}" data-n="${esc(h.nama)}"><span><span class="nm">${esc(h.nama)}</span><br><span class="mt"><span class="pill">${esc(h.tipe)}</span>No. ${esc(h.idm)}</span></span><span style="font-size:26px">→</span></button>`).join('')
      : '<div class="empty">Nama tidak ditemukan. Jika Anda bukan anggota, tekan tombol "Saya tamu".</div>';
  }
  inp.addEventListener('input', () => { clearTimeout(tmr); tmr = setTimeout(search, 250); });
  inp.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const v = inp.value.trim();
    if (/^[A-Za-z0-9\-]{5,20}$/.test(v) && /\d/.test(v)) { pending = { type: 'anggota', id: v, label: '' }; ASK ? (async () => {
        const r = await api('cari', { q: v }); const m = (r.hasil || []).find(x => x.id === v);
        if (!m) { welcome({ ok: false, pesan: 'Nomor anggota tidak ditemukan. Coba ketik nama Anda.' }); return; }
        choose({ type: 'anggota', id: v, label: m.nama.split(' ')[0] });
      })() : submit(''); }
    else { const first = res.querySelector('.res'); if (first) first.click(); }
  });
  res.onclick = e => { const b = e.target.closest('.res'); if (b) choose({ type: 'anggota', id: b.dataset.id, label: b.dataset.n.split(' ')[0] }); };

  $('#guestBtn').onclick = () => { $('#gName').value = ''; $('#gInst').value = ''; $('#gErr').textContent = ''; show('#ovGuest'); setTimeout(() => $('#gName').focus(), 50); };
  $('#gNext').onclick = () => {
    const n = $('#gName').value.trim(); if (n.length < 3) { $('#gErr').textContent = 'Nama minimal 3 huruf.'; return; }
    choose({ type: 'tamu', nama: n, instansi: $('#gInst').value.trim(), label: n.split(' ')[0] });
  };
  setInterval(() => { const d = new Date(); $('#clk').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }, 10000);
  document.addEventListener('click', e => { if (!e.target.closest('.overlay') && !e.target.closest('button') && !e.target.closest('a')) inp.focus(); });
  </script>

<?php elseif ($page === 'layar') : $L = layar_data(); ?>
  <div class="top">
    <img src="?aset=logo" alt="">
    <div><div class="k">Statistik Kunjungan</div><div class="t"><?php echo h($lib) ?></div></div>
    <div class="clock"><b id="clk"><?php echo h($L['jam']) ?></b><span id="tgl"><?php echo h($L['tanggal']) ?></span></div>
  </div>
  <div class="tv">
    <div class="big">
      <div class="stat"><b id="sHari"><?php echo $L['hari_ini'] ?></b><span>Pengunjung hari ini</span></div>
      <div class="stat gold"><b id="sMinggu"><?php echo $L['minggu_ini'] ?></b><span>Pengunjung minggu ini</span></div>
      <div class="stat sky"><b id="sBulan"><?php echo $L['bulan_ini'] ?></b><span>Pengunjung bulan <?php echo bulan(date('n')) ?></span></div>
    </div>
    <div class="card"><h3>🏆 Pengunjung Teraktif<br><small style="text-transform:none;letter-spacing:0;color:var(--muted);font-size:15px">Bulan <?php echo bulan(date('n')) ?></small></h3><ul class="lst" id="lTop"></ul></div>
    <div class="card" style="display:grid;grid-template-rows:auto 1fr;gap:8px">
      <div><h3>🕘 Baru Datang</h3><ul class="lst" id="lNew"></ul></div>
      <div><h3 style="margin-top:10px">📖 Buku Baru</h3><ul class="lst books" id="lBook"></ul></div>
    </div>
  </div>
  <div class="small-links"><a href="?p=kios">Kios</a><a href="?p=rekap">Rekap</a></div>
  <script>
  const TOKEN = <?php echo json_encode(api_token()) ?>;
  const esc = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  function render(d) {
    document.getElementById('sHari').textContent = d.hari_ini; document.getElementById('sMinggu').textContent = d.minggu_ini; document.getElementById('sBulan').textContent = d.bulan_ini;
    document.getElementById('clk').textContent = d.jam; document.getElementById('tgl').textContent = d.tanggal;
    document.getElementById('lTop').innerHTML = d.teraktif.length ? d.teraktif.map((t, i) => `<li><span><span class="rank ${i === 0 ? 'r1' : ''}">${i + 1}</span>${esc(t.nama)}</span><span class="r">${t.n}× datang</span></li>`).join('') : '<li class="empty">Belum ada kunjungan bulan ini.</li>';
    document.getElementById('lNew').innerHTML = d.terbaru.length ? d.terbaru.slice(0, 5).map(t => `<li><span>${esc(t.nama)}${t.tamu ? ' <span class="pill">Tamu</span>' : ''}</span><span class="r">${t.jam}</span></li>`).join('') : '<li class="empty">Belum ada pengunjung hari ini.</li>';
    document.getElementById('lBook').innerHTML = d.buku_baru.map(b => `<li>${esc(b)}</li>`).join('') || '<li class="empty">-</li>';
  }
  render(<?php echo json_encode($L, JSON_UNESCAPED_UNICODE) ?>);
  setInterval(async () => {
    try { const r = await fetch('?api=layar', { method: 'POST', headers: { 'X-Kios': TOKEN }, body: '{}' }); const d = await r.json(); if (d.ok) render(d); } catch (e) {}
  }, 30000);
  </script>

<?php elseif ($page === 'rekap') :
    $y = max(2020, min(2100, (int)($_GET['th'] ?? date('Y'))));
    $m = max(1, min(12, (int)($_GET['bl'] ?? date('n'))));
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-d', strtotime("$from +1 month"));
    $days = (int)date('t', strtotime($from));
    $hasPurpose = (bool)db()->query("SHOW TABLES LIKE 'bukutamu_keperluan'")->fetch();
    $rows = q("SELECT DATE(v.checkin_date) d,
                 SUM(t.member_type_name IS NOT NULL AND t.member_type_name = 'Guru') guru,
                 SUM(m.member_id IS NOT NULL AND (t.member_type_name IS NULL OR t.member_type_name <> 'Guru')) siswa,
                 SUM(m.member_id IS NULL) tamu, COUNT(*) total
               FROM visitor_count v LEFT JOIN member m ON m.member_id = v.member_id LEFT JOIN mst_member_type t ON t.member_type_id = m.member_type_id
               WHERE v.checkin_date >= ? AND v.checkin_date < ? GROUP BY DATE(v.checkin_date)", [$from, $to])->fetchAll();
    $byDay = [];
    foreach ($rows as $r) $byDay[$r['d']] = $r;
    $tot = ['siswa' => 0, 'guru' => 0, 'tamu' => 0, 'total' => 0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k];
    $activeDays = count($rows);
    $uniq = (int)q('SELECT COUNT(DISTINCT member_id) FROM visitor_count WHERE member_id IS NOT NULL AND checkin_date >= ? AND checkin_date < ?', [$from, $to])->fetchColumn();
    $top = q('SELECT v.member_id, v.member_name, t.member_type_name, COUNT(*) n FROM visitor_count v LEFT JOIN member m ON m.member_id = v.member_id LEFT JOIN mst_member_type t ON t.member_type_id = m.member_type_id
              WHERE v.member_id IS NOT NULL AND v.checkin_date >= ? AND v.checkin_date < ? GROUP BY v.member_id, v.member_name, t.member_type_name ORDER BY n DESC, v.member_name LIMIT 10', [$from, $to])->fetchAll();
    $purp = $hasPurpose ? q('SELECT k.keperluan, COUNT(*) n FROM visitor_count v JOIN bukutamu_keperluan k ON k.visitor_id = v.visitor_id WHERE v.checkin_date >= ? AND v.checkin_date < ? GROUP BY k.keperluan ORDER BY n DESC', [$from, $to])->fetchAll() : [];
    $guests = q('SELECT member_name, institution, checkin_date FROM visitor_count WHERE member_id IS NULL AND checkin_date >= ? AND checkin_date < ? ORDER BY checkin_date', [$from, $to])->fetchAll();
?>
  <div class="top noprint">
    <img src="?aset=logo" alt="">
    <div><div class="k">Rekap Kunjungan</div><div class="t"><?php echo h($lib) ?></div></div>
  </div>
  <div class="rekap">
    <form class="card filter noprint" method="get">
      <input type="hidden" name="p" value="rekap">
      <b>Periode:</b>
      <select name="bl"><?php for ($i = 1; $i <= 12; $i++) echo '<option value="' . $i . '"' . ($i == $m ? ' selected' : '') . '>' . bulan($i) . '</option>'; ?></select>
      <select name="th"><?php for ($i = (int)date('Y'); $i >= (int)date('Y') - 5; $i--) echo '<option' . ($i == $y ? ' selected' : '') . '>' . $i . '</option>'; ?></select>
      <button class="btn" type="submit">Tampilkan</button>
      <button class="btn gold" type="button" onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
      <a href="?p=kios" style="margin-left:auto">← Kios</a>
    </form>

    <div class="paper">
      <div class="kop">
        <img src="?aset=logo" alt="">
        <div class="c">
          <div class="a">PERPUSTAKAAN SEKOLAH</div>
          <div class="b"><?php echo h(mb_strtoupper($CFG['nama_sekolah'])) ?></div>
          <div class="d"><?php echo h($CFG['alamat_sekolah']) ?><?php echo !empty($mc['back_header2_text']) ? ' · ' . h(strip_tags($mc['back_header2_text'])) : '' ?></div>
        </div>
        <div style="width:64px"></div>
      </div>

      <h2>REKAPITULASI KUNJUNGAN PERPUSTAKAAN</h2>
      <div class="per">Bulan <?php echo bulan($m) . ' ' . $y ?></div>

      <div class="sum">
        <div><b><?php echo $tot['total'] ?></b>Total kunjungan</div>
        <div><b><?php echo $uniq ?></b>Anggota berbeda</div>
        <div><b><?php echo $activeDays ?></b>Hari ada kunjungan</div>
        <div><b><?php echo $activeDays ? number_format($tot['total'] / $activeDays, 1, ',', '.') : '0' ?></b>Rata-rata / hari</div>
      </div>

      <div class="cols">
        <div>
          <h4>A. Kunjungan per Hari</h4>
          <table class="t">
            <tr><th>Tgl</th><th>Hari</th><th>Siswa</th><th>Guru</th><th>Tamu</th><th>Jml</th></tr>
            <?php for ($d = 1; $d <= $days; $d++) :
                $ds = sprintf('%04d-%02d-%02d', $y, $m, $d); $ts = strtotime($ds); $r = $byDay[$ds] ?? null;
                if (!$r && in_array(date('w', $ts), ['0'])) continue; ?>
              <tr><td class="n"><?php echo $d ?></td><td><?php echo hari($ts) ?></td>
                <td class="n"><?php echo $r ? (int)$r['siswa'] : '-' ?></td><td class="n"><?php echo $r ? (int)$r['guru'] : '-' ?></td>
                <td class="n"><?php echo $r ? (int)$r['tamu'] : '-' ?></td><td class="n"><b><?php echo $r ? (int)$r['total'] : '-' ?></b></td></tr>
            <?php endfor ?>
            <tr><th colspan="2">JUMLAH</th><th><?php echo $tot['siswa'] ?></th><th><?php echo $tot['guru'] ?></th><th><?php echo $tot['tamu'] ?></th><th><?php echo $tot['total'] ?></th></tr>
          </table>
        </div>
        <div>
          <h4>B. 10 Pengunjung Teraktif</h4>
          <table class="t">
            <tr><th>No</th><th>Nama</th><th>Status</th><th>Kali</th></tr>
            <?php if (!$top) echo '<tr><td colspan="4" class="n">Belum ada data</td></tr>'; ?>
            <?php foreach ($top as $i => $t) : ?>
              <tr><td class="n"><?php echo $i + 1 ?></td><td><?php echo h($t['member_name']) ?></td><td><?php echo h($t['member_type_name'] ?: '-') ?></td><td class="n"><?php echo (int)$t['n'] ?></td></tr>
            <?php endforeach ?>
          </table>

          <?php if (!empty($CFG['tanya_keperluan'])) : ?>
          <h4>C. Keperluan Kunjungan</h4>
          <table class="t">
            <tr><th>Keperluan</th><th>Jumlah</th></tr>
            <?php if (!$purp) echo '<tr><td colspan="2" class="n">Belum ada data</td></tr>'; ?>
            <?php foreach ($purp as $p) : ?><tr><td><?php echo h($p['keperluan']) ?></td><td class="n"><?php echo (int)$p['n'] ?></td></tr><?php endforeach ?>
          </table>
          <?php endif ?>

          <h4><?php echo !empty($CFG['tanya_keperluan']) ? 'D' : 'C' ?>. Daftar Tamu (Non-Anggota)</h4>
          <table class="t">
            <tr><th>Tanggal</th><th>Nama</th><th>Instansi</th></tr>
            <?php if (!$guests) echo '<tr><td colspan="3" class="n">Tidak ada tamu</td></tr>'; ?>
            <?php foreach ($guests as $g) : ?><tr><td class="n"><?php echo date('d/m', strtotime($g['checkin_date'])) ?></td><td><?php echo h($g['member_name']) ?></td><td><?php echo h($g['institution'] ?: '-') ?></td></tr><?php endforeach ?>
          </table>
        </div>
      </div>
      <p style="font-size:10.5px;margin:2px 0 0">Hari Minggu tanpa kunjungan tidak ditampilkan. Kunjungan berulang oleh orang yang sama dalam <?php echo (int)$CFG['jeda_menit'] ?> menit dihitung satu kali.</p>

      <div class="ttd"><div>
        <?php echo h($mc['city'] ?: '') ?>, <?php echo date('j') . ' ' . bulan(date('n')) . ' ' . date('Y') ?><br>
        <?php echo h($mc['title'] ?: 'Kepala Perpustakaan') ?>
        <div class="sp"></div>
        <b><u><?php echo h($mc['officials'] ?: '..............................') ?></u></b><br>
        <?php echo $mc['officials_id'] ? 'NIP. ' . h($mc['officials_id']) : '' ?>
      </div></div>
    </div>
  </div>
<?php endif ?>
</body>
</html>
