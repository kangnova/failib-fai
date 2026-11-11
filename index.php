<?php
/**
 * File: failib/index.php
 * Portal Utama FAILib — Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA
 * Warna Tema: Royal Alexandria Teal (#0F3D3E / #0D9488) & Warm Amber-Gold (#D97706 / #F59E0B)
 * di atas permukaan Warm Ivory Paper (#FAF8F5).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helper.php';

$cfg         = failibGetSettings($pdo);
$currentAdmin = failibCurrentAdmin();
$formBuka    = ($cfg['status_form_buka'] ?? '1') === '1';

$flashSuccess = $_SESSION['failib_flash_success'] ?? '';
$flashError   = $_SESSION['failib_flash_error'] ?? '';
$lastCodes    = $_SESSION['failib_last_codes'] ?? [];
unset($_SESSION['failib_flash_success'], $_SESSION['failib_flash_error'], $_SESSION['failib_last_codes']);

// Proses Pengiriman Formulir Usulan Koleksi Buku
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'kirim_usulan_buku') {
    if (!$formBuka && !$currentAdmin) {
        $_SESSION['failib_flash_error'] = 'Periode pengajuan usulan buku saat ini sedang ditutup oleh Admin Perpustakaan.';
        header('Location: index.php');
        exit;
    }

    if (!failibVerifyCsrf()) {
        $_SESSION['failib_flash_error'] = 'Sesi keamanan formulir telah kedaluwarsa. Silakan muat ulang halaman dan kirim kembali.';
        header('Location: index.php');
        exit;
    }

    $namaPengusul      = sanitize($_POST['nama_pengusul'] ?? '');
    $kategoriPengusul  = sanitize($_POST['kategori_pengusul'] ?? 'Dosen');
    $identitasPengusul = sanitize($_POST['identitas_pengusul'] ?? '');
    $kontakPengusul    = sanitize($_POST['kontak_pengusul'] ?? '');
    $mkTerkait         = sanitize($_POST['mata_kuliah_terkait'] ?? '');
    $prioritas         = sanitize($_POST['prioritas'] ?? 'Reguler');

    if ($namaPengusul === '') {
        $namaPengusul = 'Civitas Akademika FAI UMKLA';
    }
    $validKategori = ['Dosen', 'Mahasiswa', 'Tendik', 'Prodi / Unit', 'Pustakawan', 'Lainnya'];
    if (!in_array($kategoriPengusul, $validKategori, true)) {
        $kategoriPengusul = 'Dosen';
    }
    $validPrioritas = ['Reguler', 'Penting', 'Sangat Mendesak'];
    if (!in_array($prioritas, $validPrioritas, true)) {
        $prioritas = 'Reguler';
    }

    $judulArr      = isset($_POST['judul']) && is_array($_POST['judul']) ? $_POST['judul'] : [];
    $pengarangArr  = isset($_POST['pengarang']) && is_array($_POST['pengarang']) ? $_POST['pengarang'] : [];
    $penerbitArr   = isset($_POST['penerbit']) && is_array($_POST['penerbit']) ? $_POST['penerbit'] : [];
    $tahunArr      = isset($_POST['tahun_terbit']) && is_array($_POST['tahun_terbit']) ? $_POST['tahun_terbit'] : [];
    $hargaArr      = isset($_POST['perkiraan_harga']) && is_array($_POST['perkiraan_harga']) ? $_POST['perkiraan_harga'] : [];
    $jumlahArr     = isset($_POST['jumlah_buku']) && is_array($_POST['jumlah_buku']) ? $_POST['jumlah_buku'] : [];
    $keteranganArr = isset($_POST['keterangan']) && is_array($_POST['keterangan']) ? $_POST['keterangan'] : [];

    $itemsToInsert = [];
    foreach ($judulArr as $idx => $rawJudul) {
        $judul      = sanitize($rawJudul);
        $pengarang  = sanitize($pengarangArr[$idx] ?? '');
        $penerbit   = sanitize($penerbitArr[$idx] ?? '');
        $tahun      = sanitize($tahunArr[$idx] ?? '');
        $harga      = failibParseHarga($hargaArr[$idx] ?? 0);
        $ketRaw     = sanitize($keteranganArr[$idx] ?? '');
        $jumlahBuku = failibParseJumlah($jumlahArr[$idx] ?? 1, $ketRaw);

        if ($judul === '' && $pengarang === '' && $penerbit === '') {
            continue;
        }
        if ($judul === '' || $pengarang === '' || $penerbit === '') {
            $_SESSION['failib_flash_error'] = 'Mohon lengkapi kolom JUDUL, PENGARANG, dan PENERBIT pada setiap baris buku yang diusulkan.';
            header('Location: index.php#form-usulan');
            exit;
        }

        if ($ketRaw === '') {
            $ketRaw = $jumlahBuku . ' Eksemplar';
        } elseif (!preg_match('/\b\d+\s*(eks|eksemplar|buku|buah|copy)/i', $ketRaw)) {
            $ketRaw = $jumlahBuku . ' Eksemplar — ' . $ketRaw;
        }

        $itemsToInsert[] = [
            'judul'           => $judul,
            'pengarang'       => $pengarang,
            'penerbit'        => $penerbit,
            'tahun_terbit'    => $tahun !== '' ? substr($tahun, 0, 10) : null,
            'perkiraan_harga' => $harga,
            'jumlah_buku'     => $jumlahBuku,
            'keterangan'      => $ketRaw,
        ];
    }

    if (empty($itemsToInsert)) {
        $_SESSION['failib_flash_error'] = 'Silakan isi minimal 1 usulan buku terlebih dahulu.';
        header('Location: index.php#form-usulan');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmtIns = $pdo->prepare("
            INSERT INTO failib_usulan_buku
            (kode_usulan, judul, pengarang, penerbit, tahun_terbit, perkiraan_harga, jumlah_buku, keterangan,
             nama_pengusul, kategori_pengusul, identitas_pengusul, kontak_pengusul, mata_kuliah_terkait, prioritas, status_usulan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu')
        ");

        $insertedCodes = [];
        foreach ($itemsToInsert as $item) {
            $kode = failibGenerateKodeUsulan($pdo);
            $stmtIns->execute([
                $kode,
                $item['judul'],
                $item['pengarang'],
                $item['penerbit'],
                $item['tahun_terbit'],
                $item['perkiraan_harga'],
                $item['jumlah_buku'],
                $item['keterangan'],
                $namaPengusul,
                $kategoriPengusul,
                $identitasPengusul ?: null,
                $kontakPengusul ?: null,
                $mkTerkait ?: null,
                $prioritas,
            ]);
            $insertedCodes[] = $kode;
        }
        $pdo->commit();

        $jmlBerhasil = count($insertedCodes);
        $_SESSION['failib_flash_success'] = "Alhamdulillah! Sebanyak {$jmlBerhasil} judul usulan pengadaan koleksi buku berhasil disimpan ke Perpustakaan FAI UMKLA.";
        $_SESSION['failib_last_codes']    = $insertedCodes;
        header('Location: index.php?submitted=1#katalog-usulan');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['failib_flash_error'] = 'Gagal menyimpan usulan buku: ' . $e->getMessage();
        header('Location: index.php#form-usulan');
        exit;
    }
}

// Ambil Statistik Ringkas & Daftar Usulan Terkini
$stats = [
    'total_judul'     => 0,
    'total_eksemplar' => 0,
    'total_disetujui' => 0,
    'total_tersedia'  => 0,
];
try {
    $rowStat = $pdo->query("
        SELECT
            COUNT(*) AS total_judul,
            COALESCE(SUM(jumlah_buku), 0) AS total_eksemplar,
            COALESCE(SUM(CASE WHEN status_usulan IN ('Disetujui', 'Dalam Pengadaan') THEN 1 ELSE 0 END), 0) AS total_disetujui,
            COALESCE(SUM(CASE WHEN status_usulan = 'Tersedia' THEN 1 ELSE 0 END), 0) AS total_tersedia
        FROM failib_usulan_buku
    ")->fetch();
    if ($rowStat) {
        $stats = [
            'total_judul'     => (int)$rowStat['total_judul'],
            'total_eksemplar' => (int)$rowStat['total_eksemplar'],
            'total_disetujui' => (int)$rowStat['total_disetujui'],
            'total_tersedia'  => (int)$rowStat['total_tersedia'],
        ];
    }
} catch (Exception $e) {}

$filterStatus = trim($_GET['status'] ?? '');
$searchQuery  = trim($_GET['q'] ?? '');

$whereSql = [];
$params   = [];
if ($filterStatus !== '' && in_array($filterStatus, ['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'], true)) {
    $whereSql[] = "status_usulan = ?";
    $params[]   = $filterStatus;
}
if ($searchQuery !== '') {
    $whereSql[] = "(judul LIKE ? OR pengarang LIKE ? OR penerbit LIKE ? OR kode_usulan LIKE ? OR nama_pengusul LIKE ?)";
    $like       = '%' . $searchQuery . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sqlList = "SELECT * FROM failib_usulan_buku";
if (!empty($whereSql)) {
    $sqlList .= " WHERE " . implode(" AND ", $whereSql);
}
$sqlList .= " ORDER BY id DESC LIMIT 60";

$daftarUsulan = [];
try {
    $stList = $pdo->prepare($sqlList);
    $stList->execute($params);
    $daftarUsulan = $stList->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulir Pengadaan Usulan Koleksi Buku — FAILib Perpustakaan FAI UMKLA</title>
    <link rel="icon" href="../global/favicon/favicon.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --failib-teal-deep: #0F3D3E;
            --failib-teal-main: #0D9488;
            --failib-amber-warm: #D97706;
            --failib-amber-gold: #F59E0B;
            --failib-ivory: #FAF8F5;
        }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--failib-ivory);
            color: #1E293B;
        }
        .font-serif-display {
            font-family: 'Playfair Display', Georgia, serif;
        }
        .hero-alexandria {
            background:
                radial-gradient(circle at 88% 18%, rgba(245, 158, 11, 0.24) 0%, rgba(245, 158, 11, 0) 42%),
                radial-gradient(circle at 12% 85%, rgba(20, 184, 166, 0.22) 0%, rgba(20, 184, 166, 0) 45%),
                linear-gradient(135deg, #0B2B2C 0%, #0F3D3E 45%, #164E63 100%);
        }
        .card-warm {
            background: #FFFFFF;
            box-shadow: 0 10px 30px -10px rgba(15, 61, 62, 0.08), 0 4px 12px -2px rgba(217, 119, 6, 0.04);
        }
        .pattern-dots {
            background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
            background-size: 20px 20px;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col selection:bg-amber-200 selection:text-slate-900">

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-amber-900/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-800 via-teal-700 to-amber-600 p-0.5 shadow-md group-hover:scale-105 transition">
                    <div class="w-full h-full bg-teal-950 rounded-[10px] flex items-center justify-center text-amber-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
                        </svg>
                    </div>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-extrabold text-base tracking-tight text-teal-950">FAILib</span>
                        <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-300/70">FAI UMKLA</span>
                    </div>
                    <p class="text-[11px] text-slate-500 hidden sm:block">Sistem Informasi & Pengadaan Koleksi Perpustakaan</p>
                </div>
            </a>

            <div class="flex items-center gap-2 sm:gap-3">
                <a href="#form-usulan" class="text-xs font-bold text-teal-900 hover:text-amber-700 px-3 py-2 rounded-lg hover:bg-amber-50/70 transition hidden md:inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span>Form Usulan Buku</span>
                </a>
                <a href="#katalog-usulan" class="text-xs font-bold text-slate-600 hover:text-teal-900 px-3 py-2 rounded-lg hover:bg-slate-100 transition hidden sm:inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-teal-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z"/></svg>
                    <span>Daftar Usulan (<?php echo $stats['total_judul']; ?>)</span>
                </a>
                <a href="../index.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800 px-2.5 py-2 rounded-lg hover:bg-slate-100 transition">
                    Web FAI
                </a>
                <a href="admin/index.php" class="inline-flex items-center gap-1.5 text-xs font-bold px-3.5 py-2 rounded-xl bg-teal-900 hover:bg-teal-800 text-amber-100 shadow-sm transition">
                    <svg class="w-3.5 h-3.5 text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                    <span><?php echo $currentAdmin ? 'Panel Admin' : 'Admin Perpus'; ?></span>
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Banner Alexandria Library Theme -->
    <section class="hero-alexandria text-white relative overflow-hidden">
        <div class="pattern-dots absolute inset-0 pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                <div class="lg:col-span-7 space-y-4">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/20 border border-amber-300/30 text-amber-200 text-xs font-bold">
                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                        <span>PERIODE PENGADAAN: <?php echo failibEsc(strtoupper($cfg['periode_pengadaan'] ?? 'SEMESTER AKTIF')); ?></span>
                    </div>
                    <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight leading-tight">
                        Formulir Pengadaan Usulan Koleksi Buku
                        <span class="block font-serif-display italic font-semibold text-amber-300 mt-1">
                            Perpustakaan FAI UMKLA
                        </span>
                    </h1>
                    <p class="text-teal-100/90 text-xs sm:text-sm leading-relaxed max-w-2xl">
                        <?php echo failibEsc($cfg['info_pengadaan']); ?>
                    </p>
                    <div class="pt-2 flex flex-wrap items-center gap-3">
                        <a href="#form-usulan" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-extrabold text-xs sm:text-sm shadow-lg shadow-amber-950/30 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            <span>Ajukan Usulan Buku Sekarang</span>
                        </a>
                        <a href="#katalog-usulan" class="inline-flex items-center gap-2 px-4 py-3 rounded-xl bg-white/10 hover:bg-white/15 border border-white/20 text-white font-bold text-xs sm:text-sm transition">
                            <svg class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                            <span>Cek Daftar & Status Usulan</span>
                        </a>
                    </div>
                </div>

                <!-- Statistik Koleksi & Pengadaan Card -->
                <div class="lg:col-span-5">
                    <div class="bg-white/10 backdrop-blur-md border border-white/15 rounded-2xl p-5 sm:p-6 shadow-2xl">
                        <div class="flex items-center justify-between pb-3 mb-4 border-b border-white/15">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                                <span class="text-xs font-extrabold uppercase tracking-wider text-amber-200">Ringkasan Usulan Koleksi</span>
                            </div>
                            <span class="text-[11px] text-teal-200 font-medium">Real-time Database</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-teal-950/50 border border-white/10 rounded-xl p-3.5">
                                <p class="text-[11px] text-teal-200/80 font-medium">Judul Diusulkan</p>
                                <p class="text-2xl font-extrabold text-white mt-0.5"><?php echo number_format($stats['total_judul'], 0, ',', '.'); ?></p>
                                <p class="text-[10px] text-amber-300 mt-0.5">Judul referensi</p>
                            </div>
                            <div class="bg-teal-950/50 border border-white/10 rounded-xl p-3.5">
                                <p class="text-[11px] text-teal-200/80 font-medium">Total Buku Diminta</p>
                                <p class="text-2xl font-extrabold text-amber-300 mt-0.5"><?php echo number_format($stats['total_eksemplar'], 0, ',', '.'); ?></p>
                                <p class="text-[10px] text-teal-200 mt-0.5">Total eksemplar</p>
                            </div>
                            <div class="bg-teal-950/50 border border-white/10 rounded-xl p-3.5">
                                <p class="text-[11px] text-teal-200/80 font-medium">Disetujui / Proses</p>
                                <p class="text-xl font-extrabold text-teal-300 mt-0.5"><?php echo number_format($stats['total_disetujui'], 0, ',', '.'); ?></p>
                                <p class="text-[10px] text-teal-200/70 mt-0.5">Dalam pengadaan</p>
                            </div>
                            <div class="bg-teal-950/50 border border-white/10 rounded-xl p-3.5">
                                <p class="text-[11px] text-teal-200/80 font-medium">Tersedia di Perpus</p>
                                <p class="text-xl font-extrabold text-emerald-300 mt-0.5"><?php echo number_format($stats['total_tersedia'], 0, ',', '.'); ?></p>
                                <p class="text-[10px] text-teal-200/70 mt-0.5">Siap dipinjam</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-10">

        <!-- Notifikasi Flash -->
        <?php if ($flashSuccess !== ''): ?>
            <div class="rounded-2xl bg-emerald-50 border-2 border-emerald-300 p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-emerald-950"><?php echo failibEsc($flashSuccess); ?></h3>
                        <?php if (!empty($lastCodes)): ?>
                            <p class="text-xs text-emerald-800 mt-1">
                                Nomor Tiket Usulan Anda:
                                <?php foreach ($lastCodes as $c): ?>
                                    <span class="inline-block font-mono font-bold bg-white border border-emerald-300 px-2 py-0.5 rounded text-emerald-900 mr-1"><?php echo failibEsc($c); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="#form-usulan" class="shrink-0 text-xs font-bold px-3.5 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white transition text-center">
                    + Usulkan Buku Lagi
                </a>
            </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="rounded-2xl bg-rose-50 border-2 border-rose-300 p-4 shadow-sm flex items-start gap-3">
                <div class="w-9 h-9 rounded-xl bg-rose-600 text-white flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                </div>
                <div class="text-xs sm:text-sm font-semibold text-rose-900 pt-1">
                    <?php echo failibEsc($flashError); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- SECTION 1: FORMULIR PENGADAAN USULAN KOLEKSI BUKU PERPUSTAKAAN   -->
        <!-- ================================================================ -->
        <section id="form-usulan" class="card-warm rounded-3xl border border-amber-900/10 overflow-hidden">
            <!-- Header Card Form -->
            <div class="bg-gradient-to-r from-teal-950 via-teal-900 to-teal-800 px-6 sm:px-8 py-5 text-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b-4 border-amber-500">
                <div class="flex items-center gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-amber-500/20 border border-amber-400/40 flex items-center justify-center text-amber-300 shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold tracking-wide uppercase">
                            Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA
                        </h2>
                        <p class="text-xs text-teal-100/85 mt-0.5">
                            Lengkapi data buku (Judul, Pengarang, Penerbit, Perkiraan Harga, &amp; Keterangan Jumlah Buku yang Diminta). Anda dapat menambahkan lebih dari 1 judul sekaligus.
                        </p>
                    </div>
                </div>
                <div class="shrink-0">
                    <?php if ($formBuka): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/20 border border-emerald-400/40 text-emerald-200 text-xs font-bold">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            Formulir Terbuka
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-rose-500/20 border border-rose-400/40 text-rose-200 text-xs font-bold">
                            <span class="w-2 h-2 rounded-full bg-rose-400"></span>
                            Periode Ditutup
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$formBuka && !$currentAdmin): ?>
                <div class="p-8 text-center space-y-3">
                    <div class="w-14 h-14 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center mx-auto">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="text-base font-extrabold text-slate-800">Periode Pengajuan Usulan Buku Sedang Ditutup Sementara</h3>
                    <p class="text-xs text-slate-500 max-w-lg mx-auto">
                        Terima kasih atas partisipasi Anda. Saat ini Tim Perpustakaan FAI UMKLA sedang memproses pengadaan buku dari gelombang usulan sebelumnya.
                    </p>
                </div>
            <?php else: ?>
                <form action="index.php" method="POST" id="formUsulanBuku" class="p-6 sm:p-8 space-y-7">
                    <?php echo failibCsrfField(); ?>
                    <input type="hidden" name="action" value="kirim_usulan_buku">

                    <!-- Blok A: Identitas Pengusul -->
                    <div class="bg-gradient-to-br from-amber-50/70 via-white to-teal-50/40 border border-amber-200/80 rounded-2xl p-5">
                        <div class="flex items-center justify-between mb-4 pb-2.5 border-b border-amber-200/60">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-teal-900 text-amber-300 text-xs font-extrabold flex items-center justify-center">1</span>
                                <h3 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-teal-950">
                                    Data Pengusul (Dosen / Mahasiswa / Unit FAI UMKLA)
                                </h3>
                            </div>
                            <span class="text-[11px] text-slate-500">Tersimpan otomatis di perangkat Anda</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">
                                    Nama Lengkap Pengusul <span class="text-rose-500">*</span>
                                </label>
                                <input type="text" name="nama_pengusul" id="inpNamaPengusul" required maxlength="150"
                                    placeholder="Contoh: Dr. H. Ahmad, M.Ag."
                                    class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">
                                    Kategori Pengusul <span class="text-rose-500">*</span>
                                </label>
                                <select name="kategori_pengusul" id="inpKategoriPengusul"
                                    class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                    <option value="Dosen">Dosen Pengampu</option>
                                    <option value="Mahasiswa">Mahasiswa FAI</option>
                                    <option value="Prodi / Unit">Program Studi / Unit FAI</option>
                                    <option value="Tendik">Tenaga Kependidikan (Tendik)</option>
                                    <option value="Pustakawan">Pustakawan</option>
                                    <option value="Lainnya">Lainnya</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">
                                    NIDN / NIM / Prodi <span class="text-slate-400 font-normal">(Opsional)</span>
                                </label>
                                <input type="text" name="identitas_pengusul" id="inpIdentitasPengusul" maxlength="100"
                                    placeholder="NIDN / NIM / Prodi PAI"
                                    class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">
                                    No. WhatsApp &amp; Mata Kuliah <span class="text-slate-400 font-normal">(Opsional)</span>
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" name="kontak_pengusul" id="inpKontakPengusul" maxlength="45"
                                        placeholder="0812xxxx"
                                        class="w-full px-3 py-2.5 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none transition">
                                    <input type="text" name="mata_kuliah_terkait" id="inpMkTerkait" maxlength="120"
                                        placeholder="Mata Kuliah"
                                        class="w-full px-3 py-2.5 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none transition">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Blok B: Daftar Usulan Koleksi Buku (Judul, Pengarang, Penerbit, Perkiraan Harga, Keterangan Jumlah Buku) -->
                    <div>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-amber-600 text-white text-xs font-extrabold flex items-center justify-center">2</span>
                                <div>
                                    <h3 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-teal-950">
                                        Rincian Koleksi Buku yang Diusulkan
                                    </h3>
                                    <p class="text-[11px] text-slate-500">
                                        Isi <strong>Judul</strong>, <strong>Pengarang</strong>, <strong>Penerbit</strong>, <strong>Perkiraan Harga</strong>, dan <strong>Keterangan (Jumlah Buku yang Diminta)</strong>.
                                    </p>
                                </div>
                            </div>

                            <button type="button" onclick="tambahBarisBuku()"
                                class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-amber-100 hover:bg-amber-200 text-amber-950 border border-amber-300 text-xs font-extrabold transition cursor-pointer shrink-0 shadow-2xs">
                                <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                <span>Tambah Baris Buku Lainnya</span>
                            </button>
                        </div>

                        <!-- Container Baris Buku -->
                        <div id="containerBarisBuku" class="space-y-4">
                            <!-- Baris Pertama (Default) -->
                            <div class="baris-buku relative rounded-2xl border-2 border-teal-900/15 bg-slate-50/70 hover:bg-white hover:border-teal-700/40 p-5 transition shadow-2xs">
                                <div class="flex items-center justify-between mb-3.5 pb-2 border-b border-slate-200/80">
                                    <div class="flex items-center gap-2">
                                        <span class="badge-nomor-buku inline-flex items-center justify-center px-2.5 py-0.5 rounded-lg bg-teal-900 text-amber-300 text-xs font-extrabold">
                                            Buku #1
                                        </span>
                                        <span class="text-xs font-semibold text-slate-500">Data Koleksi Buku Perpustakaan</span>
                                    </div>
                                    <button type="button" onclick="hapusBarisBuku(this)"
                                        class="btn-hapus-baris hidden items-center gap-1 text-xs font-bold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-2.5 py-1 rounded-lg transition cursor-pointer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        <span>Hapus Baris</span>
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                                    <!-- 1. JUDUL -->
                                    <div class="md:col-span-5">
                                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                                            JUDUL BUKU <span class="text-rose-500">*</span>
                                        </label>
                                        <input type="text" name="judul[]" required maxlength="255"
                                            placeholder="Masukkan judul lengkap buku..."
                                            class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-semibold text-slate-900 focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                    </div>

                                    <!-- 2. PENGARANG -->
                                    <div class="md:col-span-4">
                                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                                            PENGARANG / PENULIS <span class="text-rose-500">*</span>
                                        </label>
                                        <input type="text" name="pengarang[]" required maxlength="200"
                                            placeholder="Nama pengarang / penulis buku..."
                                            class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                    </div>

                                    <!-- 3. PENERBIT -->
                                    <div class="md:col-span-3">
                                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                                            PENERBIT <span class="text-rose-500">*</span>
                                        </label>
                                        <input type="text" name="penerbit[]" required maxlength="180"
                                            placeholder="Nama penerbit (&amp; tahun)..."
                                            class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                    </div>

                                    <!-- 4. PERKIRAAN HARGA -->
                                    <div class="md:col-span-4">
                                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                                            PERKIRAAN HARGA (SATUAN) <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-extrabold text-teal-800 pointer-events-none">Rp</span>
                                            <input type="text" name="perkiraan_harga[]" required inputmode="numeric"
                                                placeholder="85.000"
                                                oninput="formatInputRupiah(this); hitungTotalEstimasi();"
                                                class="inp-harga w-full pl-9 pr-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-mono font-bold text-slate-900 focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                        </div>
                                        <p class="text-[10px] text-slate-400 mt-1">Perkiraan harga per 1 eksemplar buku</p>
                                    </div>

                                    <!-- 5. KETERANGAN (JUMLAH BUKU YANG DIMINTA) -->
                                    <div class="md:col-span-5">
                                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                                            KETERANGAN (JUMLAH BUKU YANG DIMINTA) <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="flex items-stretch gap-2">
                                            <div class="w-28 shrink-0">
                                                <div class="relative flex items-center">
                                                    <input type="number" name="jumlah_buku[]" value="1" min="1" max="999" required
                                                        oninput="hitungTotalEstimasi()"
                                                        title="Jumlah Eksemplar Buku yang Diminta"
                                                        class="inp-jumlah w-full pl-3 pr-10 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-extrabold text-teal-950 focus:ring-2 focus:ring-teal-700 focus:outline-none">
                                                    <span class="absolute right-2.5 text-[10px] font-bold text-slate-400 pointer-events-none">Eks</span>
                                                </div>
                                            </div>
                                            <input type="text" name="keterangan[]" maxlength="255"
                                                placeholder="Contoh: 3 Buku (Referensi Wajib MK Fiqh Muamalah)"
                                                class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:border-teal-700 focus:outline-none transition">
                                        </div>
                                        <p class="text-[10px] text-slate-400 mt-1">Isi jumlah eksemplar beserta keterangan peruntukan buku</p>
                                    </div>

                                    <!-- SUBTOTAL OTOMATIS -->
                                    <div class="md:col-span-3 flex flex-col justify-center">
                                        <div class="bg-amber-50/90 border border-amber-200 rounded-xl px-3.5 py-2.5 flex items-center justify-between md:flex-col md:items-end">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-amber-800">Estimasi Subtotal</span>
                                            <span class="label-subtotal text-sm font-mono font-extrabold text-teal-950 mt-0.5">Rp 0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Kalkulasi & Tombol Submit -->
                        <div class="mt-6 pt-5 border-t border-slate-200 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" onclick="tambahBarisBuku()"
                                    class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border-2 border-dashed border-teal-700/40 hover:border-teal-700 text-teal-900 hover:bg-teal-50/60 text-xs font-extrabold transition cursor-pointer">
                                    <svg class="w-4 h-4 text-teal-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                    <span>Tambah Judul Buku Lainnya</span>
                                </button>

                                <div class="bg-teal-950 text-white px-4 py-2.5 rounded-xl flex items-center gap-4 shadow-sm">
                                    <div>
                                        <span class="text-[10px] uppercase tracking-wider text-teal-200 block">Total Buku</span>
                                        <span id="grandTotalEksemplar" class="text-xs sm:text-sm font-extrabold text-amber-300">1 Judul (1 Eks)</span>
                                    </div>
                                    <div class="h-6 w-px bg-white/15"></div>
                                    <div>
                                        <span class="text-[10px] uppercase tracking-wider text-teal-200 block">Total Estimasi Harga</span>
                                        <span id="grandTotalHarga" class="text-xs sm:text-sm font-mono font-extrabold text-white">Rp 0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <select name="prioritas" class="px-3.5 py-3 bg-slate-100 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-700">
                                    <option value="Reguler">Prioritas: Reguler</option>
                                    <option value="Penting">Prioritas: Penting (MK Aktif)</option>
                                    <option value="Sangat Mendesak">Prioritas: Sangat Mendesak</option>
                                </select>

                                <button type="submit"
                                    class="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-r from-teal-900 via-teal-800 to-teal-900 hover:from-teal-800 hover:to-teal-700 text-amber-200 font-extrabold text-xs sm:text-sm shadow-lg shadow-teal-950/20 transition cursor-pointer">
                                    <svg class="w-4 h-4 text-amber-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                    <span>Kirim Usulan Koleksi Buku</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <!-- ================================================================ -->
        <!-- SECTION 2: DAFTAR & PELACAKAN STATUS USULAN BUKU TERKINI         -->
        <!-- ================================================================ -->
        <section id="katalog-usulan" class="card-warm rounded-3xl border border-amber-900/10 p-6 sm:p-8">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-5 mb-6 border-b border-slate-200/80">
                <div>
                    <div class="inline-flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider text-amber-700 mb-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                        <span>Transparansi Pengadaan Koleksi</span>
                    </div>
                    <h2 class="text-lg sm:text-xl font-extrabold text-teal-950">
                        Daftar Usulan Koleksi Buku Perpustakaan FAI UMKLA
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Pantau perkembangan usulan buku mulai dari tahap verifikasi hingga buku tersedia di rak Perpustakaan FAI UMKLA.
                    </p>
                </div>

                <!-- Form Filter & Pencarian -->
                <form action="index.php#katalog-usulan" method="GET" class="flex flex-wrap sm:flex-nowrap items-center gap-2">
                    <select name="status" onchange="this.form.submit()"
                        class="px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        <option value="">Semua Status</option>
                        <?php foreach (['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'] as $stOpt): ?>
                            <option value="<?php echo $stOpt; ?>" <?php echo $filterStatus === $stOpt ? 'selected' : ''; ?>>
                                <?php echo $stOpt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="relative flex-1 sm:w-64">
                        <input type="text" name="q" value="<?php echo failibEsc($searchQuery); ?>"
                            placeholder="Cari judul, pengarang, penerbit..."
                            class="w-full pl-3.5 pr-9 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        <button type="submit" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-teal-800">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                        </button>
                    </div>
                    <?php if ($filterStatus !== '' || $searchQuery !== ''): ?>
                        <a href="index.php#katalog-usulan" class="text-xs font-bold text-rose-600 hover:underline px-2 py-1">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($daftarUsulan)): ?>
                <div class="border-2 border-dashed border-slate-200 rounded-2xl p-10 text-center space-y-2 bg-slate-50/50">
                    <div class="w-12 h-12 rounded-2xl bg-teal-900/10 text-teal-800 flex items-center justify-center mx-auto">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    </div>
                    <p class="text-sm font-bold text-slate-700">Belum Ada Data Usulan Buku yang Ditampilkan</p>
                    <p class="text-xs text-slate-500">Jadilah yang pertama mengusulkan pengadaan koleksi buku baru menggunakan formulir di atas.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-teal-950 text-white text-[11px] font-extrabold uppercase tracking-wider">
                                <th class="py-3.5 px-4 w-12 text-center">No</th>
                                <th class="py-3.5 px-4">Judul Buku</th>
                                <th class="py-3.5 px-4">Pengarang</th>
                                <th class="py-3.5 px-4">Penerbit</th>
                                <th class="py-3.5 px-4 text-right">Perkiraan Harga</th>
                                <th class="py-3.5 px-4">Keterangan (Jumlah Buku Diminta)</th>
                                <th class="py-3.5 px-4">Pengusul</th>
                                <th class="py-3.5 px-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-xs bg-white">
                            <?php foreach ($daftarUsulan as $i => $row):
                                $meta = failibStatusMeta($row['status_usulan'] ?? 'Menunggu');
                                $subtotal = (float)$row['perkiraan_harga'] * (int)$row['jumlah_buku'];
                            ?>
                                <tr class="hover:bg-amber-50/40 transition">
                                    <td class="py-3.5 px-4 text-center font-bold text-slate-400"><?php echo $i + 1; ?></td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-extrabold text-slate-900 text-xs sm:text-sm"><?php echo failibEsc($row['judul']); ?></div>
                                        <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                            <span class="font-mono text-[10px] font-semibold text-teal-800 bg-teal-50 border border-teal-200 px-1.5 py-0.5 rounded">
                                                <?php echo failibEsc($row['kode_usulan']); ?>
                                            </span>
                                            <?php if (!empty($row['mata_kuliah_terkait'])): ?>
                                                <span class="text-[10px] font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">
                                                    MK: <?php echo failibEsc($row['mata_kuliah_terkait']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 font-semibold text-slate-700"><?php echo failibEsc($row['pengarang']); ?></td>
                                    <td class="py-3.5 px-4 text-slate-600">
                                        <?php echo failibEsc($row['penerbit']); ?>
                                        <?php if (!empty($row['tahun_terbit'])): ?>
                                            <span class="text-slate-400">(<?php echo failibEsc($row['tahun_terbit']); ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-right font-mono">
                                        <div class="font-bold text-slate-800"><?php echo failibRupiah($row['perkiraan_harga']); ?></div>
                                        <?php if ((int)$row['jumlah_buku'] > 1): ?>
                                            <div class="text-[10px] text-slate-400">Total: <?php echo failibRupiah($subtotal); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-100 text-amber-900 font-extrabold text-[11px] mr-1">
                                            <?php echo (int)$row['jumlah_buku']; ?> Buku
                                        </span>
                                        <span class="text-slate-600"><?php echo failibEsc($row['keterangan']); ?></span>
                                        <?php if (!empty($row['catatan_admin'])): ?>
                                            <div class="mt-1 text-[10px] text-teal-800 bg-teal-50/80 border border-teal-200 rounded px-2 py-1">
                                                <strong>Catatan Perpus:</strong> <?php echo failibEsc($row['catatan_admin']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-bold text-slate-800"><?php echo failibEsc($row['nama_pengusul']); ?></div>
                                        <div class="text-[10px] text-slate-400"><?php echo failibEsc($row['kategori_pengusul']); ?> • <?php echo failibTanggalIndo($row['created_at']); ?></div>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] font-bold <?php echo $meta['bg']; ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?php echo $meta['dot']; ?>"></span>
                                            <?php echo failibEsc($meta['label']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-teal-950 text-teal-100/80 border-t-4 border-amber-500 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                <p>&copy; <?php echo date('Y'); ?> <strong>FAILib — Perpustakaan Fakultas Agama Islam</strong> Universitas Muhammadiyah Klaten.</p>
            </div>
            <div class="flex items-center gap-4">
                <a href="index.php" class="hover:text-amber-300 transition">Form Usulan Buku</a>
                <span class="text-teal-700">&bull;</span>
                <a href="admin/index.php" class="hover:text-amber-300 transition">Portal Admin Perpustakaan</a>
                <span class="text-teal-700">&bull;</span>
                <a href="../index.php" class="hover:text-amber-300 transition">Website Utama FAI</a>
            </div>
        </div>
    </footer>

    <script>
        // Simpan & muat otomatis identitas pengusul di localStorage agar praktis
        const STORAGE_KEYS = ['inpNamaPengusul', 'inpKategoriPengusul', 'inpIdentitasPengusul', 'inpKontakPengusul'];
        document.addEventListener('DOMContentLoaded', () => {
            STORAGE_KEYS.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const saved = localStorage.getItem('failib_' + id);
                if (saved && !el.value) el.value = saved;
                el.addEventListener('change', () => localStorage.setItem('failib_' + id, el.value));
            });
            hitungTotalEstimasi();
        });

        function formatInputRupiah(input) {
            let angka = (input.value || '').replace(/[^0-9]/g, '');
            if (angka === '') {
                input.value = '';
                return;
            }
            input.value = parseInt(angka, 10).toLocaleString('id-ID');
        }

        function parseAngkaRupiah(str) {
            let clean = (str || '').replace(/[^0-9]/g, '');
            return clean ? parseInt(clean, 10) : 0;
        }

        function hitungTotalEstimasi() {
            const rows = document.querySelectorAll('#containerBarisBuku .baris-buku');
            let totalJudul = rows.length;
            let totalEks = 0;
            let totalHarga = 0;

            rows.forEach((row, index) => {
                const badge = row.querySelector('.badge-nomor-buku');
                if (badge) badge.textContent = 'Buku #' + (index + 1);

                const btnHapus = row.querySelector('.btn-hapus-baris');
                if (btnHapus) {
                    if (rows.length > 1) {
                        btnHapus.classList.remove('hidden');
                        btnHapus.classList.add('inline-flex');
                    } else {
                        btnHapus.classList.add('hidden');
                        btnHapus.classList.remove('inline-flex');
                    }
                }

                const harga = parseAngkaRupiah(row.querySelector('.inp-harga')?.value || '0');
                const jml = Math.max(1, parseInt(row.querySelector('.inp-jumlah')?.value || '1', 10) || 1);
                const sub = harga * jml;

                totalEks += jml;
                totalHarga += sub;

                const subEl = row.querySelector('.label-subtotal');
                if (subEl) {
                    subEl.textContent = 'Rp ' + sub.toLocaleString('id-ID');
                }
            });

            const elEks = document.getElementById('grandTotalEksemplar');
            const elHarga = document.getElementById('grandTotalHarga');
            if (elEks) elEks.textContent = totalJudul + ' Judul (' + totalEks + ' Eks)';
            if (elHarga) elHarga.textContent = 'Rp ' + totalHarga.toLocaleString('id-ID');
        }

        function tambahBarisBuku() {
            const container = document.getElementById('containerBarisBuku');
            const firstRow = container.querySelector('.baris-buku');
            if (!firstRow) return;

            const clone = firstRow.cloneNode(true);
            clone.querySelectorAll('input').forEach(inp => {
                if (inp.classList.contains('inp-jumlah')) {
                    inp.value = '1';
                } else {
                    inp.value = '';
                }
            });
            container.appendChild(clone);
            hitungTotalEstimasi();
            clone.querySelector('input[name="judul[]"]')?.focus();
        }

        function hapusBarisBuku(btn) {
            const container = document.getElementById('containerBarisBuku');
            const rows = container.querySelectorAll('.baris-buku');
            if (rows.length <= 1) return;
            btn.closest('.baris-buku')?.remove();
            hitungTotalEstimasi();
        }
    </script>
</body>
</html>

