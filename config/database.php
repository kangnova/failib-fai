<?php
/**
 * File: failib/config/database.php
 * Koneksi Database Terpusat FAILib — Perpustakaan Fakultas Agama Islam UMKLA.
 * Terintegrasi langsung dengan src/Config/database.php serta auto-inisialisasi skema failib_*.
 */

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    @session_start();
}

$centralPaths = [
    __DIR__ . '/../../src/Config/database.php',
    dirname(__DIR__, 2) . '/src/Config/database.php',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/src/Config/database.php',
];

foreach ($centralPaths as $cfgPath) {
    if (!empty($cfgPath) && is_file($cfgPath)) {
        require_once $cfgPath;
        if (isset($pdo) && ($pdo instanceof PDO)) {
            break;
        }
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Koneksi database terpusat (src/Config/database.php) tidak ditemukan atau gagal diinisialisasi.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET time_zone = '+07:00'");

if (!function_exists('sanitize')) {
    function sanitize($data) {
        if (is_array($data)) {
            return array_map('sanitize', $data);
        }
        $str = trim((string)$data);
        return str_replace(["'", "’", "‘", "ʼ", "′"], "`", $str);
    }
}

// Auto-inisialisasi tabel FAILib di database terpusat
try {
    $cekTabel = $pdo->query("SHOW TABLES LIKE 'failib_usulan_buku'")->fetchColumn();
    if (!$cekTabel) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `failib_usulan_buku` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `kode_usulan` VARCHAR(40) NOT NULL,
                `judul` VARCHAR(255) NOT NULL,
                `pengarang` VARCHAR(200) NOT NULL,
                `penerbit` VARCHAR(180) NOT NULL,
                `tahun_terbit` VARCHAR(10) DEFAULT NULL,
                `isbn` VARCHAR(50) DEFAULT NULL,
                `perkiraan_harga` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `jumlah_buku` INT NOT NULL DEFAULT 1,
                `keterangan` TEXT DEFAULT NULL,
                `nama_pengusul` VARCHAR(150) NOT NULL DEFAULT 'Civitas Akademika FAI',
                `kategori_pengusul` ENUM('Dosen', 'Mahasiswa', 'Tendik', 'Prodi / Unit', 'Pustakawan', 'Lainnya') NOT NULL DEFAULT 'Dosen',
                `identitas_pengusul` VARCHAR(100) DEFAULT NULL,
                `kontak_pengusul` VARCHAR(50) DEFAULT NULL,
                `mata_kuliah_terkait` VARCHAR(150) DEFAULT NULL,
                `prioritas` ENUM('Reguler', 'Penting', 'Sangat Mendesak') NOT NULL DEFAULT 'Reguler',
                `status_usulan` ENUM('Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak') NOT NULL DEFAULT 'Menunggu',
                `catatan_admin` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_status_usulan` (`status_usulan`),
                INDEX `idx_kode_usulan` (`kode_usulan`),
                INDEX `idx_judul` (`judul`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $cekAdminTbl = $pdo->query("SHOW TABLES LIKE 'failib_admin'")->fetchColumn();
    if (!$cekAdminTbl) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `failib_admin` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(60) NOT NULL UNIQUE,
                `nama_lengkap` VARCHAR(120) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` VARCHAR(30) NOT NULL DEFAULT 'admin',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $hashDefault = password_hash('admin-123', PASSWORD_DEFAULT);
        $stSeed = $pdo->prepare("
            INSERT IGNORE INTO `failib_admin` (`username`, `nama_lengkap`, `password_hash`, `role`, `is_active`)
            VALUES
            ('admin', 'Administrator Perpustakaan FAI UMKLA', ?, 'superadmin', 1)
        ");
        $stSeed->execute([$hashDefault]);
    } else {
        // Pastikan akun default 'admin' aktif & bila masih menggunakan hash awal lama, perbarui ke default baru
        $stCheckAdm = $pdo->prepare("SELECT id, password_hash FROM `failib_admin` WHERE `username` = 'admin' LIMIT 1");
        $stCheckAdm->execute();
        $admRow = $stCheckAdm->fetch();
        if (!$admRow) {
            $hashDefault = password_hash('admin-123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO `failib_admin` (`username`, `nama_lengkap`, `password_hash`, `role`, `is_active`) VALUES ('admin', 'Administrator Perpustakaan FAI UMKLA', ?, 'superadmin', 1)")->execute([$hashDefault]);
        } elseif (password_verify('failib123', $admRow['password_hash'])) {
            $hashDefault = password_hash('admin-123', PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE `failib_admin` SET `password_hash` = ?, `is_active` = 1 WHERE `id` = ?")->execute([$hashDefault, (int)$admRow['id']]);
            $pdo->exec("DELETE FROM `failib_admin` WHERE `username` = 'pustakawan'");
        }
    }

    $cekCfgTbl = $pdo->query("SHOW TABLES LIKE 'failib_pengaturan'")->fetchColumn();
    if (!$cekCfgTbl) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `failib_pengaturan` (
                `kunci` VARCHAR(80) PRIMARY KEY,
                `nilai` TEXT DEFAULT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $defaultCfg = [
            'nama_sistem'             => 'FAILib — Perpustakaan FAI UMKLA',
            'subjudul_sistem'         => 'Portal Pengadaan Usulan Koleksi Buku & Literasi Akademik Fakultas Agama Islam Universitas Muhammadiyah Klaten',
            'periode_pengadaan'       => 'Semester Ganjil 2026/2027',
            'status_form_buka'        => '1',
            'tampilkan_daftar_publik' => '1',
            'kepala_perpustakaan'     => 'Kepala Perpustakaan FAI UMKLA',
            'nip_kepala_perpustakaan' => '-',
            'dekan_fai'               => 'Dekan Fakultas Agama Islam UMKLA',
            'nidn_dekan_fai'          => '-',
            'kontak_wa'               => '',
            'info_pengadaan'          => 'Silakan ajukan usulan buku referensi perkuliahan, riset, maupun pengayaan literasi Islam. Setiap usulan akan diverifikasi oleh Tim Perpustakaan & pimpinan FAI UMKLA.',
        ];
        $stInsCfg = $pdo->prepare("INSERT IGNORE INTO `failib_pengaturan` (`kunci`, `nilai`) VALUES (?, ?)");
        foreach ($defaultCfg as $k => $v) {
            $stInsCfg->execute([$k, $v]);
        }
    }
} catch (Exception $e) {
    error_log('[FAILIB-INIT] ' . $e->getMessage());
}

