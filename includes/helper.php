<?php
/**
 * File: failib/includes/helper.php
 * Fungsi Utilitas, Keamanan CSRF, Format Rupiah, Status Badge, & Autentikasi Admin FAILib UMKLA.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

function failibEsc($val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}

function failibCsrfToken(): string {
    if (empty($_SESSION['failib_csrf_token']) || empty($_SESSION['failib_csrf_time']) || (time() - (int)$_SESSION['failib_csrf_time']) > 7200) {
        try {
            $_SESSION['failib_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['failib_csrf_token'] = hash('sha256', uniqid('failib_', true) . mt_rand());
        }
    }
    $_SESSION['failib_csrf_time'] = time();
    return $_SESSION['failib_csrf_token'];
}

function failibCsrfField(): string {
    return '<input type="hidden" name="failib_csrf_token" value="' . failibEsc(failibCsrfToken()) . '">';
}

function failibVerifyCsrf($tokenInput = null): bool {
    $sessionToken = $_SESSION['failib_csrf_token'] ?? '';
    $postToken    = $tokenInput ?? ($_POST['failib_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sessionToken === '' || $postToken === '') {
        return false;
    }
    $ok = hash_equals((string)$sessionToken, (string)$postToken);
    if ($ok) {
        $_SESSION['failib_csrf_time'] = time();
    }
    return $ok;
}

/**
 * Ambil pengaturan FAILib
 */
function failibGetSettings(PDO $pdo, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $defaults = [
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
    try {
        $rows = $pdo->query("SELECT kunci, nilai FROM failib_pengaturan")->fetchAll();
        foreach ($rows as $r) {
            $defaults[$r['kunci']] = (string)($r['nilai'] ?? '');
        }
    } catch (Exception $e) {}
    $cache = $defaults;
    return $cache;
}

function failibSaveSetting(PDO $pdo, string $kunci, string $nilai): void {
    $stmt = $pdo->prepare("
        INSERT INTO failib_pengaturan (kunci, nilai)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)
    ");
    $stmt->execute([$kunci, $nilai]);
    failibGetSettings($pdo, true);
}

/**
 * Format angka menjadi Rupiah (contoh: Rp 85.000)
 */
function failibRupiah($nominal, bool $withPrefix = true): string {
    $num = (float)($nominal ?? 0);
    $formatted = number_format($num, 0, ',', '.');
    return $withPrefix ? 'Rp ' . $formatted : $formatted;
}

/**
 * Bersihkan input nominal harga dari titik pemisah ribuan/koma/Rp menjadi float murni
 * Menangani format input pengguna seperti "100.000", "100000", "Rp 100.000", atau "100.000,00"
 */
function failibParseHarga($raw): float {
    if (is_int($raw) || is_float($raw)) {
        return max(0, (float)$raw);
    }
    $str = trim((string)$raw);
    if ($str === '') {
        return 0.0;
    }
    // Jika format desimal database standar (contoh: "100000.00")
    if (preg_match('/^\d+\.00$/', $str)) {
        $str = substr($str, 0, -3);
    } else {
        // Hapus desimal sen koma nol jika ada (contoh: "100.000,00")
        $str = preg_replace('/,00?$/', '', $str);
    }
    // Hapus semua selain angka (termasuk titik pemisah ribuan "100.000" -> "100000")
    $clean = preg_replace('/[^0-9]/', '', $str);
    return $clean !== '' ? (float)$clean : 0.0;
}

/**
 * Parse angka jumlah buku dari input jumlah atau string keterangan
 */
function failibParseJumlah($rawJumlah, string $keterangan = ''): int {
    $jml = (int)preg_replace('/[^0-9]/', '', (string)$rawJumlah);
    if ($jml > 0) {
        return min(9999, $jml);
    }
    if (preg_match('/(\d+)\s*(eks|eksemplar|buku|buah|pcs|copy)/i', $keterangan, $m)) {
        return max(1, min(9999, (int)$m[1]));
    }
    if (preg_match('/^(\d+)$/', trim($keterangan), $m)) {
        return max(1, min(9999, (int)$m[1]));
    }
    return 1;
}

/**
 * Generate Kode Usulan Unik (contoh: USL-202611-0001)
 */
function failibGenerateKodeUsulan(PDO $pdo): string {
    $prefix = 'USL-' . date('Ym') . '-';
    try {
        $stmt = $pdo->prepare("SELECT kode_usulan FROM failib_usulan_buku WHERE kode_usulan LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
            $next = (int)$m[1] + 1;
        } else {
            $next = 1;
        }
    } catch (Exception $e) {
        $next = mt_rand(100, 999);
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Format Tanggal Bahasa Indonesia
 */
function failibTanggalIndo($datetime, bool $withTime = false): string {
    if (empty($datetime) || $datetime === '0000-00-00' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    $bulan = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    $ts = strtotime((string)$datetime);
    if (!$ts) return (string)$datetime;
    $str = date('d', $ts) . ' ' . ($bulan[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    if ($withTime) {
        $str .= ' • ' . date('H:i', $ts);
    }
    return $str;
}

/**
 * Metadata Badge Status Usulan Buku
 */
function failibStatusMeta(string $status): array {
    $map = [
        'Menunggu' => [
            'label' => 'Menunggu Review',
            'bg'    => 'bg-amber-50 text-amber-800 border-amber-200',
            'dot'   => 'bg-amber-500',
        ],
        'Disetujui' => [
            'label' => 'Disetujui',
            'bg'    => 'bg-teal-50 text-teal-800 border-teal-200',
            'dot'   => 'bg-teal-500',
        ],
        'Dalam Pengadaan' => [
            'label' => 'Dalam Pengadaan',
            'bg'    => 'bg-sky-50 text-sky-800 border-sky-200',
            'dot'   => 'bg-sky-500',
        ],
        'Tersedia' => [
            'label' => 'Tersedia di Perpus',
            'bg'    => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            'dot'   => 'bg-emerald-500',
        ],
        'Ditolak' => [
            'label' => 'Belum Dapat Dipenuhi',
            'bg'    => 'bg-rose-50 text-rose-800 border-rose-200',
            'dot'   => 'bg-rose-500',
        ],
    ];
    return $map[$status] ?? $map['Menunggu'];
}

/**
 * Autentikasi Admin FAILib (Mendukung sesi failib_admin serta SSO Admin Terpusat FAI UMKLA)
 */
function failibCurrentAdmin(): ?array {
    if (!empty($_SESSION['failib_admin']) && is_array($_SESSION['failib_admin'])) {
        return $_SESSION['failib_admin'];
    }
    // SSO otomatis bila sudah login sebagai Admin di portal utama / UMClass / Kepegawaian
    if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin', 'adminfai'], true)) {
        return [
            'id'           => (int)$_SESSION['user_id'],
            'username'     => (string)($_SESSION['username'] ?? 'admin'),
            'nama_lengkap' => 'Admin Portal FAI (' . ($_SESSION['username'] ?? 'admin') . ')',
            'role'         => 'superadmin',
        ];
    }
    if (!empty($_SESSION['umc_user']) && ($_SESSION['umc_user']['role'] ?? '') === 'ADMIN') {
        return [
            'id'           => (int)$_SESSION['umc_user']['id'],
            'username'     => (string)$_SESSION['umc_user']['username'],
            'nama_lengkap' => (string)($_SESSION['umc_user']['nama_lengkap'] ?? 'Admin UMClass'),
            'role'         => 'superadmin',
        ];
    }
    if (!empty($_SESSION['admin_pegawai_id'])) {
        return [
            'id'           => (int)$_SESSION['admin_pegawai_id'],
            'username'     => (string)($_SESSION['admin_pegawai_user'] ?? 'admin'),
            'nama_lengkap' => (string)($_SESSION['admin_pegawai_nama'] ?? 'Admin Kepegawaian FAI'),
            'role'         => 'admin',
        ];
    }
    return null;
}

function failibRequireAdmin(string $loginUrl = 'login.php'): array {
    $admin = failibCurrentAdmin();
    if (!$admin) {
        header('Location: ' . $loginUrl);
        exit;
    }
    return $admin;
}

/**
 * Verifikasi Kredensial Login Admin ke Tabel failib_admin ATAU Tabel Admin Terpusat FAI UMKLA
 */
function failibAttemptLogin(PDO $pdo, string $username, string $password): ?array {
    // 1. Cek tabel failib_admin
    try {
        $st = $pdo->prepare("SELECT * FROM failib_admin WHERE username = ? AND is_active = 1 LIMIT 1");
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            return [
                'id'           => (int)$row['id'],
                'username'     => $row['username'],
                'nama_lengkap' => $row['nama_lengkap'],
                'role'         => $row['role'],
            ];
        }
    } catch (Exception $e) {}

    // 2. Fallback SSO ke tabel users (Admin Utama Website FAI)
    try {
        $st2 = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1");
        $st2->execute([$username]);
        $u = $st2->fetch();
        if ($u && password_verify($password, $u['password'])) {
            return [
                'id'           => (int)$u['id'],
                'username'     => $u['username'],
                'nama_lengkap' => 'Administrator FAI (' . $u['username'] . ')',
                'role'         => $u['role'] ?: 'admin',
            ];
        }
    } catch (Exception $e) {}

    // 3. Fallback SSO ke tabel umc_users (Admin UMClass)
    try {
        $st3 = $pdo->prepare("SELECT * FROM umc_users WHERE username = ? AND role = 'ADMIN' AND is_active = 1 LIMIT 1");
        $st3->execute([$username]);
        $umc = $st3->fetch();
        if ($umc && password_verify($password, $umc['password_hash'])) {
            return [
                'id'           => (int)$umc['id'],
                'username'     => $umc['username'],
                'nama_lengkap' => $umc['nama_lengkap'],
                'role'         => 'superadmin',
            ];
        }
    } catch (Exception $e) {}

    // 4. Fallback SSO ke tabel admin_data_pegawai
    try {
        $st4 = $pdo->prepare("SELECT * FROM admin_data_pegawai WHERE (username = ? OR email = ?) AND role != 'tamu' LIMIT 1");
        $st4->execute([$username, $username]);
        $admPgw = $st4->fetch();
        if ($admPgw && password_verify($password, $admPgw['password'])) {
            return [
                'id'           => (int)$admPgw['id'],
                'username'     => $admPgw['username'],
                'nama_lengkap' => $admPgw['nama_lengkap'] ?? 'Admin FAI UMKLA',
                'role'         => 'admin',
            ];
        }
    } catch (Exception $e) {}

    return null;
}

