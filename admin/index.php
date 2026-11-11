<?php
/**
 * File: failib/admin/index.php
 * Dashboard Admin Perpustakaan FAILib — Kelola Pengadaan Usulan Koleksi Buku FAI UMKLA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helper.php';

$admin = failibRequireAdmin('login.php');
$cfg   = failibGetSettings($pdo);

$flashSuccess = $_SESSION['failib_admin_success'] ?? '';
$flashError   = $_SESSION['failib_admin_error'] ?? '';
unset($_SESSION['failib_admin_success'], $_SESSION['failib_admin_error']);

// Handle POST Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!failibVerifyCsrf()) {
        $_SESSION['failib_admin_error'] = 'Sesi keamanan kedaluwarsa. Silakan ulangi tindakan.';
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. Simpan (Tambah / Edit) Usulan Buku
    if ($action === 'simpan_usulan') {
        $id                = (int)($_POST['id'] ?? 0);
        $judul             = sanitize($_POST['judul'] ?? '');
        $pengarang         = sanitize($_POST['pengarang'] ?? '');
        $penerbit          = sanitize($_POST['penerbit'] ?? '');
        $tahunTerbit       = sanitize($_POST['tahun_terbit'] ?? '');
        $harga             = failibParseHarga($_POST['perkiraan_harga'] ?? 0);
        $ketRaw            = sanitize($_POST['keterangan'] ?? '');
        $jumlahBuku        = failibParseJumlah($_POST['jumlah_buku'] ?? 1, $ketRaw);
        $namaPengusul      = sanitize($_POST['nama_pengusul'] ?? 'Civitas Akademika FAI');
        $kategoriPengusul  = sanitize($_POST['kategori_pengusul'] ?? 'Dosen');
        $identitasPengusul = sanitize($_POST['identitas_pengusul'] ?? '');
        $kontakPengusul    = sanitize($_POST['kontak_pengusul'] ?? '');
        $mkTerkait         = sanitize($_POST['mata_kuliah_terkait'] ?? '');
        $prioritas         = sanitize($_POST['prioritas'] ?? 'Reguler');
        $statusUsulan      = sanitize($_POST['status_usulan'] ?? 'Menunggu');
        $catatanAdmin      = sanitize($_POST['catatan_admin'] ?? '');

        if ($judul === '' || $pengarang === '' || $penerbit === '') {
            $_SESSION['failib_admin_error'] = 'Kolom Judul, Pengarang, dan Penerbit wajib diisi.';
            header('Location: index.php');
            exit;
        }

        if ($ketRaw === '') {
            $ketRaw = $jumlahBuku . ' Eksemplar';
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE failib_usulan_buku
                    SET judul = ?, pengarang = ?, penerbit = ?, tahun_terbit = ?, perkiraan_harga = ?,
                        jumlah_buku = ?, keterangan = ?, nama_pengusul = ?, kategori_pengusul = ?,
                        identitas_pengusul = ?, kontak_pengusul = ?, mata_kuliah_terkait = ?,
                        prioritas = ?, status_usulan = ?, catatan_admin = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $judul, $pengarang, $penerbit, $tahunTerbit ?: null, $harga,
                    $jumlahBuku, $ketRaw, $namaPengusul, $kategoriPengusul,
                    $identitasPengusul ?: null, $kontakPengusul ?: null, $mkTerkait ?: null,
                    $prioritas, $statusUsulan, $catatanAdmin ?: null, $id
                ]);
                $_SESSION['failib_admin_success'] = 'Data usulan buku "' . $judul . '" berhasil diperbarui.';
            } else {
                $kode = failibGenerateKodeUsulan($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO failib_usulan_buku
                    (kode_usulan, judul, pengarang, penerbit, tahun_terbit, perkiraan_harga, jumlah_buku, keterangan,
                     nama_pengusul, kategori_pengusul, identitas_pengusul, kontak_pengusul, mata_kuliah_terkait,
                     prioritas, status_usulan, catatan_admin)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $kode, $judul, $pengarang, $penerbit, $tahunTerbit ?: null, $harga, $jumlahBuku, $ketRaw,
                    $namaPengusul, $kategoriPengusul, $identitasPengusul ?: null, $kontakPengusul ?: null,
                    $mkTerkait ?: null, $prioritas, $statusUsulan, $catatanAdmin ?: null
                ]);
                $_SESSION['failib_admin_success'] = 'Usulan buku baru "' . $judul . '" berhasil ditambahkan.';
            }
        } catch (Exception $e) {
            $_SESSION['failib_admin_error'] = 'Gagal menyimpan data: ' . $e->getMessage();
        }
        header('Location: index.php');
        exit;
    }

    // 2. Update Status Cepat
    if ($action === 'ubah_status_cepat') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status_usulan'] ?? 'Menunggu');
        $validStatuses = ['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'];
        if ($id > 0 && in_array($status, $validStatuses, true)) {
            $pdo->prepare("UPDATE failib_usulan_buku SET status_usulan = ? WHERE id = ?")->execute([$status, $id]);
            $_SESSION['failib_admin_success'] = "Status usulan buku berhasil diubah menjadi '{$status}'.";
        }
        header('Location: index.php?' . http_build_query($_GET));
        exit;
    }

    // 3. Hapus Usulan Buku
    if ($action === 'hapus_usulan') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM failib_usulan_buku WHERE id = ?")->execute([$id]);
            $_SESSION['failib_admin_success'] = 'Data usulan buku berhasil dihapus.';
        }
        header('Location: index.php');
        exit;
    }

    // 4. Aksi Massal (Bulk Action)
    if ($action === 'bulk_action') {
        $ids      = isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
        $bulkType = $_POST['bulk_type'] ?? '';
        $ids      = array_values(array_filter($ids, fn($v) => $v > 0));

        if (empty($ids)) {
            $_SESSION['failib_admin_error'] = 'Pilih minimal 1 baris usulan buku terlebih dahulu.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($bulkType === 'hapus') {
                $st = $pdo->prepare("DELETE FROM failib_usulan_buku WHERE id IN ($placeholders)");
                $st->execute($ids);
                $_SESSION['failib_admin_success'] = count($ids) . ' usulan buku berhasil dihapus.';
            } elseif (in_array($bulkType, ['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'], true)) {
                $st = $pdo->prepare("UPDATE failib_usulan_buku SET status_usulan = ? WHERE id IN ($placeholders)");
                $st->execute(array_merge([$bulkType], $ids));
                $_SESSION['failib_admin_success'] = count($ids) . " usulan buku berhasil diubah statusnya menjadi '{$bulkType}'.";
            }
        }
        header('Location: index.php');
        exit;
    }

    // 5. Simpan Pengaturan Portal & Pejabat
    if ($action === 'simpan_pengaturan') {
        $keys = [
            'periode_pengadaan',
            'status_form_buka',
            'kepala_perpustakaan',
            'nip_kepala_perpustakaan',
            'dekan_fai',
            'nidn_dekan_fai',
            'info_pengadaan',
        ];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                failibSaveSetting($pdo, $k, sanitize($_POST[$k]));
            }
        }
        $_SESSION['failib_admin_success'] = 'Pengaturan portal FAILib & pejabat penandatangan berhasil disimpan.';
        header('Location: index.php');
        exit;
    }

    // 6. Ubah Password Admin di Panel Pengaturan
    if ($action === 'ubah_password_admin') {
        $passLama       = (string)($_POST['password_lama'] ?? '');
        $passBaru       = (string)($_POST['password_baru'] ?? '');
        $passKonfirmasi = (string)($_POST['konfirmasi_password'] ?? '');
        $namaBaru       = sanitize($_POST['nama_lengkap_admin'] ?? ($admin['nama_lengkap'] ?? 'Administrator Perpustakaan FAI UMKLA'));

        if ($passLama === '' || $passBaru === '' || $passKonfirmasi === '') {
            $_SESSION['failib_admin_error'] = 'Password lama, password baru, dan konfirmasi password wajib diisi.';
        } elseif (strlen($passBaru) < 6) {
            $_SESSION['failib_admin_error'] = 'Password baru minimal terdiri dari 6 karakter.';
        } elseif ($passBaru !== $passKonfirmasi) {
            $_SESSION['failib_admin_error'] = 'Konfirmasi password baru tidak cocok.';
        } else {
            $usernameTarget = $admin['username'] ?? 'admin';
            $stAdm = $pdo->prepare("SELECT * FROM failib_admin WHERE username = ? LIMIT 1");
            $stAdm->execute([$usernameTarget]);
            $rowAdm = $stAdm->fetch();

            if (!$rowAdm) {
                $stAdm = $pdo->prepare("SELECT * FROM failib_admin WHERE username = 'admin' LIMIT 1");
                $stAdm->execute();
                $rowAdm = $stAdm->fetch();
            }

            if (!$rowAdm || !password_verify($passLama, $rowAdm['password_hash'])) {
                $_SESSION['failib_admin_error'] = 'Password lama yang Anda masukkan tidak sesuai.';
            } else {
                $newHash = password_hash($passBaru, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE failib_admin SET password_hash = ?, nama_lengkap = ? WHERE id = ?");
                $upd->execute([$newHash, $namaBaru ?: $rowAdm['nama_lengkap'], (int)$rowAdm['id']]);
                if (!empty($_SESSION['failib_admin'])) {
                    $_SESSION['failib_admin']['nama_lengkap'] = $namaBaru ?: $rowAdm['nama_lengkap'];
                }
                $_SESSION['failib_admin_success'] = 'Password akun Admin Perpustakaan berhasil diperbarui.';
            }
        }
        header('Location: index.php');
        exit;
    }
}

// Ringkasan Statistik Admin
$rekap = [
    'total_judul'            => 0,
    'total_eksemplar'        => 0,
    'total_anggaran'         => 0.0,
    'anggaran_disetujui'     => 0.0,
    'cnt_menunggu'           => 0,
    'cnt_disetujui'          => 0,
    'cnt_pengadaan'          => 0,
    'cnt_tersedia'           => 0,
    'cnt_ditolak'            => 0,
];
try {
    $r = $pdo->query("
        SELECT
            COUNT(*) AS total_judul,
            COALESCE(SUM(jumlah_buku), 0) AS total_eksemplar,
            COALESCE(SUM(perkiraan_harga * jumlah_buku), 0) AS total_anggaran,
            COALESCE(SUM(CASE WHEN status_usulan IN ('Disetujui', 'Dalam Pengadaan', 'Tersedia') THEN perkiraan_harga * jumlah_buku ELSE 0 END), 0) AS anggaran_disetujui,
            COALESCE(SUM(CASE WHEN status_usulan = 'Menunggu' THEN 1 ELSE 0 END), 0) AS cnt_menunggu,
            COALESCE(SUM(CASE WHEN status_usulan = 'Disetujui' THEN 1 ELSE 0 END), 0) AS cnt_disetujui,
            COALESCE(SUM(CASE WHEN status_usulan = 'Dalam Pengadaan' THEN 1 ELSE 0 END), 0) AS cnt_pengadaan,
            COALESCE(SUM(CASE WHEN status_usulan = 'Tersedia' THEN 1 ELSE 0 END), 0) AS cnt_tersedia,
            COALESCE(SUM(CASE WHEN status_usulan = 'Ditolak' THEN 1 ELSE 0 END), 0) AS cnt_ditolak
        FROM failib_usulan_buku
    ")->fetch();
    if ($r) {
        $rekap = $r;
    }
} catch (Exception $e) {}

// Filter & Pencarian
$filterStatus   = trim($_GET['status'] ?? '');
$filterKategori = trim($_GET['kategori'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, ['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'], true)) {
    $where[]  = "status_usulan = ?";
    $params[] = $filterStatus;
}
if ($filterKategori !== '' && in_array($filterKategori, ['Dosen', 'Mahasiswa', 'Tendik', 'Prodi / Unit', 'Pustakawan', 'Lainnya'], true)) {
    $where[]  = "kategori_pengusul = ?";
    $params[] = $filterKategori;
}
if ($searchQuery !== '') {
    $where[] = "(judul LIKE ? OR pengarang LIKE ? OR penerbit LIKE ? OR keterangan LIKE ? OR nama_pengusul LIKE ? OR kode_usulan LIKE ?)";
    $lk = '%' . $searchQuery . '%';
    array_push($params, $lk, $lk, $lk, $lk, $lk, $lk);
}

$sql = "SELECT * FROM failib_usulan_buku";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$listUsulan = $st->fetchAll();

$queryExport = http_build_query([
    'status'   => $filterStatus,
    'kategori' => $filterKategori,
    'q'        => $searchQuery,
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin FAILib — Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA</title>
    <link rel="icon" href="../../global/favicon/favicon.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #FAF8F5;
            color: #1E293B;
        }
        .header-alexandria {
            background: linear-gradient(135deg, #0B2B2C 0%, #0F3D3E 55%, #164E63 100%);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Top Admin Bar -->
    <header class="header-alexandria text-white border-b-4 border-amber-500 sticky top-0 z-40 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="index.php" class="w-10 h-10 rounded-xl bg-amber-500/20 border border-amber-400/40 flex items-center justify-center text-amber-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
                    </svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-extrabold text-base tracking-tight">ADMIN FAILib</span>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-amber-500 text-slate-950">Perpustakaan FAI UMKLA</span>
                    </div>
                    <p class="text-[11px] text-teal-200/80 hidden sm:block">Manajemen Pengadaan Usulan Koleksi Buku • Login: <?php echo failibEsc($admin['nama_lengkap']); ?></p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="bukaModalPengaturan()"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-amber-200 transition cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="hidden sm:inline">Pengaturan</span>
                </button>
                <a href="../index.php" target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-white transition">
                    <svg class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                    <span class="hidden sm:inline">Lihat Portal Publik</span>
                </a>
                <a href="logout.php"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-xl bg-rose-600/90 hover:bg-rose-600 text-xs font-bold text-white transition">
                    <span>Keluar</span>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7 flex-1 w-full space-y-6">

        <?php if ($flashSuccess !== ''): ?>
            <div class="bg-emerald-50 border-2 border-emerald-300 text-emerald-950 px-4 py-3.5 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-2xs">
                <div class="flex items-center gap-2.5">
                    <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?php echo failibEsc($flashSuccess); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="bg-rose-50 border-2 border-rose-300 text-rose-900 px-4 py-3.5 rounded-2xl text-xs sm:text-sm font-bold flex items-center gap-2.5 shadow-2xs">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <span><?php echo failibEsc($flashError); ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistik Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Koleksi Diusulkan</span>
                    <span class="w-9 h-9 rounded-xl bg-teal-50 text-teal-800 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    </span>
                </div>
                <p class="text-2xl font-extrabold text-teal-950 mt-2">
                    <?php echo number_format((int)$rekap['total_judul'], 0, ',', '.'); ?> <span class="text-sm font-bold text-slate-500">Judul</span>
                </p>
                <p class="text-xs font-semibold text-amber-700 mt-1">
                    Total diminta: <?php echo number_format((int)$rekap['total_eksemplar'], 0, ',', '.'); ?> Eksemplar Buku
                </p>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Estimasi Harga</span>
                    <span class="w-9 h-9 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </div>
                <p class="text-xl font-mono font-extrabold text-slate-900 mt-2">
                    <?php echo failibRupiah($rekap['total_anggaran']); ?>
                </p>
                <p class="text-xs text-slate-500 mt-1">
                    Disetujui/Proses: <strong class="text-teal-800"><?php echo failibRupiah($rekap['anggaran_disetujui']); ?></strong>
                </p>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Menunggu &amp; Proses</span>
                    <span class="w-9 h-9 rounded-xl bg-sky-50 text-sky-700 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </div>
                <div class="flex items-baseline gap-3 mt-2">
                    <div>
                        <span class="text-2xl font-extrabold text-amber-600"><?php echo (int)$rekap['cnt_menunggu']; ?></span>
                        <span class="text-xs text-slate-500 font-semibold">Menunggu</span>
                    </div>
                    <span class="text-slate-300">|</span>
                    <div>
                        <span class="text-2xl font-extrabold text-teal-700"><?php echo (int)$rekap['cnt_disetujui'] + (int)$rekap['cnt_pengadaan']; ?></span>
                        <span class="text-xs text-slate-500 font-semibold">Disetujui/Pengadaan</span>
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-1">Klik status pada tabel untuk verifikasi cepat</p>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Tersedia di Perpus</span>
                    <span class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </div>
                <p class="text-2xl font-extrabold text-emerald-700 mt-2">
                    <?php echo (int)$rekap['cnt_tersedia']; ?> <span class="text-sm font-bold text-slate-500">Judul Selesai</span>
                </p>
                <p class="text-xs text-slate-500 mt-1">
                    Periode: <strong><?php echo failibEsc($cfg['periode_pengadaan']); ?></strong>
                </p>
            </div>
        </div>

        <!-- Action Bar & Filter -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs space-y-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 pb-4 border-b border-slate-100">
                <div>
                    <h2 class="text-base sm:text-lg font-extrabold text-teal-950 uppercase tracking-wide">
                        Rekapitulasi Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA
                    </h2>
                    <p class="text-xs text-slate-500">
                        Menampilkan <strong><?php echo count($listUsulan); ?></strong> baris usulan buku. Anda dapat menambah, mengedit, mengubah status, mengekspor ke Excel, atau mencetak surat pengadaan resmi.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" onclick="bukaModalTambah()"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-teal-900 hover:bg-teal-800 text-amber-200 text-xs font-extrabold shadow-xs transition cursor-pointer">
                        <svg class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>Tambah Usulan Buku</span>
                    </button>

                    <a href="export_excel.php?<?php echo failibEsc($queryExport); ?>"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold shadow-xs transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        <span>Export Excel (.xls)</span>
                    </a>

                    <a href="cetak.php?<?php echo failibEsc($queryExport); ?>" target="_blank"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-xs transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"/></svg>
                        <span>Cetak Dokumen Pengadaan</span>
                    </a>
                </div>
            </div>

            <!-- Baris Filter & Pencarian -->
            <form action="index.php" method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                <div class="sm:col-span-3">
                    <select name="status" onchange="this.form.submit()"
                        class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        <option value="">Semua Status Usulan</option>
                        <?php foreach (['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'] as $stOpt): ?>
                            <option value="<?php echo $stOpt; ?>" <?php echo $filterStatus === $stOpt ? 'selected' : ''; ?>>
                                Status: <?php echo $stOpt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-3">
                    <select name="kategori" onchange="this.form.submit()"
                        class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        <option value="">Semua Kategori Pengusul</option>
                        <?php foreach (['Dosen', 'Mahasiswa', 'Prodi / Unit', 'Tendik', 'Pustakawan', 'Lainnya'] as $katOpt): ?>
                            <option value="<?php echo $katOpt; ?>" <?php echo $filterKategori === $katOpt ? 'selected' : ''; ?>>
                                Pengusul: <?php echo $katOpt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-5 relative">
                    <input type="text" name="q" value="<?php echo failibEsc($searchQuery); ?>"
                        placeholder="Cari judul buku, pengarang, penerbit, pengusul, atau kode usulan..."
                        class="w-full pl-3.5 pr-9 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    <button type="submit" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-teal-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                    </button>
                </div>

                <div class="sm:col-span-1 flex justify-end">
                    <a href="index.php" class="w-full text-center px-3 py-2 rounded-xl border border-slate-200 hover:bg-slate-100 text-xs font-bold text-slate-600 transition">
                        Reset
                    </a>
                </div>
            </form>

            <!-- Tabel Utama Usulan Koleksi Buku -->
            <form action="index.php" method="POST" id="formBulkAction">
                <?php echo failibCsrfField(); ?>
                <input type="hidden" name="action" value="bulk_action">

                <div class="flex flex-wrap items-center justify-between gap-2 mb-3 bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2">
                    <label class="inline-flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer select-none">
                        <input type="checkbox" id="chkAll" onchange="toggleSelectAll(this)" class="w-4 h-4 rounded border-slate-300 text-teal-800 focus:ring-teal-700">
                        <span>Pilih Semua Baris</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <select name="bulk_type" class="px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-700">
                            <option value="">-- Aksi Massal untuk yang Dicentang --</option>
                            <option value="Disetujui">Setujui Usulan Terpilih</option>
                            <option value="Dalam Pengadaan">Tandai Dalam Pengadaan</option>
                            <option value="Tersedia">Tandai Tersedia di Perpustakaan</option>
                            <option value="Menunggu">Kembalikan ke Menunggu Review</option>
                            <option value="Ditolak">Tandai Belum Dapat Dipenuhi</option>
                            <option value="hapus">Hapus Usulan Terpilih</option>
                        </select>
                        <button type="submit" onclick="return konfirmasiBulk()"
                            class="px-3.5 py-1.5 rounded-lg bg-teal-900 hover:bg-teal-800 text-white text-xs font-bold transition cursor-pointer">
                            Terapkan
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-teal-950 text-white text-[11px] font-extrabold uppercase tracking-wider">
                                <th class="py-3.5 px-3 w-10 text-center">#</th>
                                <th class="py-3.5 px-3 w-10 text-center">No</th>
                                <th class="py-3.5 px-4">JUDUL</th>
                                <th class="py-3.5 px-4">PENGARANG</th>
                                <th class="py-3.5 px-4">PENERBIT</th>
                                <th class="py-3.5 px-4 text-right">PERKIRAAN HARGA</th>
                                <th class="py-3.5 px-4">KETERANGAN (JUMLAH BUKU YANG DIMINTA)</th>
                                <th class="py-3.5 px-4">PENGUSUL</th>
                                <th class="py-3.5 px-4 text-center">STATUS PENGADAAN</th>
                                <th class="py-3.5 px-4 text-center w-36">AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-xs bg-white">
                            <?php if (empty($listUsulan)): ?>
                                <tr>
                                    <td colspan="10" class="py-10 text-center text-slate-400 italic">
                                        Belum ada data usulan pengadaan buku yang sesuai dengan kriteria pencarian.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $grandTotalEks = 0;
                                $grandTotalNominal = 0;
                                foreach ($listUsulan as $idx => $item):
                                    $sub = (float)$item['perkiraan_harga'] * (int)$item['jumlah_buku'];
                                    $grandTotalEks += (int)$item['jumlah_buku'];
                                    $grandTotalNominal += $sub;
                                    $meta = failibStatusMeta($item['status_usulan'] ?? 'Menunggu');
                                ?>
                                    <tr class="hover:bg-amber-50/40 transition align-top">
                                        <td class="py-3.5 px-3 text-center">
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo (int)$item['id']; ?>" class="chk-row w-4 h-4 rounded border-slate-300 text-teal-800">
                                        </td>
                                        <td class="py-3.5 px-3 text-center font-bold text-slate-400"><?php echo $idx + 1; ?></td>
                                        <td class="py-3.5 px-4">
                                            <div class="font-extrabold text-slate-900 text-xs sm:text-sm"><?php echo failibEsc($item['judul']); ?></div>
                                            <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                                <span class="font-mono text-[10px] font-bold text-teal-800 bg-teal-50 border border-teal-200 px-1.5 py-0.5 rounded">
                                                    <?php echo failibEsc($item['kode_usulan']); ?>
                                                </span>
                                                <?php if ($item['prioritas'] !== 'Reguler'): ?>
                                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded <?php echo $item['prioritas'] === 'Sangat Mendesak' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'; ?>">
                                                        <?php echo failibEsc($item['prioritas']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 font-semibold text-slate-700"><?php echo failibEsc($item['pengarang']); ?></td>
                                        <td class="py-3.5 px-4 text-slate-600">
                                            <?php echo failibEsc($item['penerbit']); ?>
                                            <?php if (!empty($item['tahun_terbit'])): ?>
                                                <span class="text-slate-400">(<?php echo failibEsc($item['tahun_terbit']); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-right font-mono">
                                            <div class="font-bold text-slate-900"><?php echo failibRupiah($item['perkiraan_harga']); ?></div>
                                            <div class="text-[10px] text-teal-800 font-semibold mt-0.5">
                                                Subtotal: <?php echo failibRupiah($sub); ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-100 text-amber-950 font-extrabold text-[11px]">
                                                    <?php echo (int)$item['jumlah_buku']; ?> Buku / Eks
                                                </span>
                                                <?php if (!empty($item['mata_kuliah_terkait'])): ?>
                                                    <span class="text-[10px] font-bold text-teal-800 bg-teal-50 border border-teal-200 px-1.5 py-0.5 rounded">
                                                        MK: <?php echo failibEsc($item['mata_kuliah_terkait']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-slate-600"><?php echo failibEsc($item['keterangan']); ?></div>
                                            <?php if (!empty($item['catatan_admin'])): ?>
                                                <div class="mt-1.5 text-[10px] bg-slate-100 border border-slate-200 rounded px-2 py-1 text-slate-700">
                                                    <strong>Catatan Admin:</strong> <?php echo failibEsc($item['catatan_admin']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-slate-800"><?php echo failibEsc($item['nama_pengusul']); ?></div>
                                            <div class="text-[10px] text-slate-500">
                                                <?php echo failibEsc($item['kategori_pengusul']); ?>
                                                <?php if (!empty($item['identitas_pengusul'])): ?>
                                                    • <?php echo failibEsc($item['identitas_pengusul']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($item['kontak_pengusul'])): ?>
                                                <div class="text-[10px] text-teal-700 font-mono mt-0.5"><?php echo failibEsc($item['kontak_pengusul']); ?></div>
                                            <?php endif; ?>
                                            <div class="text-[10px] text-slate-400 mt-0.5"><?php echo failibTanggalIndo($item['created_at']); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <select onchange="ubahStatusCepat(<?php echo (int)$item['id']; ?>, this.value)"
                                                class="px-2.5 py-1.5 rounded-xl border text-[11px] font-extrabold cursor-pointer focus:outline-none <?php echo $meta['bg']; ?>">
                                                <?php foreach (['Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak'] as $stOpt): ?>
                                                    <option value="<?php echo $stOpt; ?>" <?php echo $item['status_usulan'] === $stOpt ? 'selected' : ''; ?>>
                                                        <?php echo $stOpt; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button type="button"
                                                    data-item="<?php echo failibEsc(json_encode($item)); ?>"
                                                    onclick="bukaModalEdit(this)"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white hover:bg-teal-50 text-teal-900 border border-teal-200 text-[11px] font-bold transition shadow-2xs cursor-pointer">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                                                    <span>Edit</span>
                                                </button>
                                                <button type="button"
                                                    onclick="hapusUsulan(<?php echo (int)$item['id']; ?>, <?php echo failibEsc(json_encode($item['judul'])); ?>)"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 text-[11px] font-bold transition shadow-2xs cursor-pointer">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                                    <span>Hapus</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="bg-amber-50/80 font-extrabold text-xs border-t-2 border-amber-300">
                                    <td colspan="5" class="py-3.5 px-4 text-right uppercase tracking-wider text-teal-950">
                                        Total Rekapitulasi Tampil (<?php echo count($listUsulan); ?> Judul):
                                    </td>
                                    <td class="py-3.5 px-4 text-right font-mono text-teal-950 text-sm">
                                        <?php echo failibRupiah($grandTotalNominal); ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-amber-950">
                                        <?php echo number_format($grandTotalEks, 0, ',', '.'); ?> Eksemplar Buku
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </main>

    <!-- Form Tersembunyi untuk Ubah Status Cepat & Hapus -->
    <form id="formStatusCepat" action="index.php?<?php echo failibEsc($queryExport); ?>" method="POST" class="hidden">
        <?php echo failibCsrfField(); ?>
        <input type="hidden" name="action" value="ubah_status_cepat">
        <input type="hidden" name="id" id="inpStatusCepatId" value="">
        <input type="hidden" name="status_usulan" id="inpStatusCepatVal" value="">
    </form>

    <form id="formHapusUsulan" action="index.php" method="POST" class="hidden">
        <?php echo failibCsrfField(); ?>
        <input type="hidden" name="action" value="hapus_usulan">
        <input type="hidden" name="id" id="inpHapusId" value="">
    </form>

    <!-- ================================================================ -->
    <!-- MODAL TAMBAH / EDIT USULAN BUKU                                  -->
    <!-- ================================================================ -->
    <div id="modalUsulan" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs overflow-y-auto">
        <div class="bg-white rounded-3xl border border-slate-200 shadow-2xl w-full max-w-2xl overflow-hidden my-8">
            <div class="header-alexandria text-white px-6 py-4 flex items-center justify-between border-b-4 border-amber-500">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-500/20 border border-amber-400/40 flex items-center justify-center text-amber-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    </div>
                    <div>
                        <h3 id="modalUsulanTitle" class="text-base font-extrabold">Tambah Usulan Koleksi Buku</h3>
                        <p class="text-[11px] text-teal-100/80">Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA</p>
                    </div>
                </div>
                <button type="button" onclick="tutupModalUsulan()" class="text-teal-100 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form action="index.php" method="POST" class="p-6 space-y-4">
                <?php echo failibCsrfField(); ?>
                <input type="hidden" name="action" value="simpan_usulan">
                <input type="hidden" name="id" id="modalId" value="0">

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
                    <div class="sm:col-span-12">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            JUDUL BUKU <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="judul" id="modalJudul" required maxlength="255"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            PENGARANG <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="pengarang" id="modalPengarang" required maxlength="200"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            PENERBIT <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="penerbit" id="modalPenerbit" required maxlength="180"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            TAHUN
                        </label>
                        <input type="text" name="tahun_terbit" id="modalTahun" maxlength="10" placeholder="2025"
                            class="w-full px-3 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            PERKIRAAN HARGA (RP) <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="perkiraan_harga" id="modalHarga" required
                            oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-mono font-bold focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-3">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            JUMLAH BUKU <span class="text-rose-500">*</span>
                        </label>
                        <input type="number" name="jumlah_buku" id="modalJumlah" min="1" max="999" value="1" required
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-extrabold focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">
                            KETERANGAN (JUMLAH BUKU DIMINTA)
                        </label>
                        <input type="text" name="keterangan" id="modalKeterangan" maxlength="255"
                            placeholder="Contoh: 3 Eksemplar — Referensi MK"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Nama Pengusul</label>
                        <input type="text" name="nama_pengusul" id="modalNamaPengusul" required
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Pengusul</label>
                        <select name="kategori_pengusul" id="modalKategori"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                            <option value="Dosen">Dosen</option>
                            <option value="Mahasiswa">Mahasiswa</option>
                            <option value="Prodi / Unit">Prodi / Unit</option>
                            <option value="Tendik">Tendik</option>
                            <option value="Pustakawan">Pustakawan</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Mata Kuliah Terkait</label>
                        <input type="text" name="mata_kuliah_terkait" id="modalMk"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">NIDN / NIM / Unit</label>
                        <input type="text" name="identitas_pengusul" id="modalIdentitas"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Kontak / WhatsApp</label>
                        <input type="text" name="kontak_pengusul" id="modalKontak"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Prioritas</label>
                        <select name="prioritas" id="modalPrioritas"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                            <option value="Reguler">Reguler</option>
                            <option value="Penting">Penting</option>
                            <option value="Sangat Mendesak">Sangat Mendesak</option>
                        </select>
                    </div>

                    <div class="sm:col-span-5">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">Status Pengadaan</label>
                        <select name="status_usulan" id="modalStatus"
                            class="w-full px-3.5 py-2.5 bg-amber-50 border border-amber-300 rounded-xl text-xs font-extrabold text-teal-950 focus:ring-2 focus:ring-teal-700 focus:outline-none">
                            <option value="Menunggu">Menunggu Review</option>
                            <option value="Disetujui">Disetujui</option>
                            <option value="Dalam Pengadaan">Dalam Pengadaan</option>
                            <option value="Tersedia">Tersedia di Perpustakaan</option>
                            <option value="Ditolak">Belum Dapat Dipenuhi (Ditolak)</option>
                        </select>
                    </div>

                    <div class="sm:col-span-7">
                        <label class="block text-xs font-extrabold uppercase tracking-wider text-teal-950 mb-1">Catatan Pustakawan / Admin</label>
                        <input type="text" name="catatan_admin" id="modalCatatanAdmin"
                            placeholder="Contoh: Sudah dipesan via vendor / Tersedia di Rak 03"
                            class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                    <button type="button" onclick="tutupModalUsulan()"
                        class="px-4 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 text-xs font-bold transition cursor-pointer">
                        Batal
                    </button>
                    <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-teal-900 hover:bg-teal-800 text-amber-200 text-xs font-extrabold shadow-md transition cursor-pointer">
                        Simpan Data Usulan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- MODAL PENGATURAN PORTAL, PEJABAT & UBAH PASSWORD ADMIN           -->
    <!-- ================================================================ -->
    <div id="modalPengaturan" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs overflow-y-auto">
        <div class="bg-white rounded-3xl border border-slate-200 shadow-2xl w-full max-w-2xl overflow-hidden my-8">
            <div class="header-alexandria text-white px-6 py-4 flex items-center justify-between border-b-4 border-amber-500">
                <div>
                    <h3 class="text-base font-extrabold">Pengaturan Panel Admin FAILib</h3>
                    <p class="text-[11px] text-teal-100/80">Kelola periode pengadaan, pejabat penandatangan, serta ubah password akun admin</p>
                </div>
                <button type="button" onclick="tutupModalPengaturan()" class="text-teal-100 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-6 space-y-6 max-h-[82vh] overflow-y-auto">
                <!-- Bagian 1: Pengaturan Portal & Pejabat Penandatangan -->
                <form action="index.php" method="POST" class="space-y-4">
                    <?php echo failibCsrfField(); ?>
                    <input type="hidden" name="action" value="simpan_pengaturan">

                    <div class="flex items-center gap-2 pb-2 border-b border-slate-200">
                        <span class="w-6 h-6 rounded-lg bg-teal-900 text-amber-300 text-xs font-extrabold flex items-center justify-center">1</span>
                        <h4 class="text-xs font-extrabold uppercase tracking-wider text-teal-950">Pengaturan Portal &amp; Pejabat Cetak Surat</h4>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Periode Pengadaan Aktif</label>
                            <input type="text" name="periode_pengadaan" value="<?php echo failibEsc($cfg['periode_pengadaan']); ?>"
                                class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Status Formulir Publik</label>
                            <select name="status_form_buka" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                                <option value="1" <?php echo ($cfg['status_form_buka'] ?? '1') === '1' ? 'selected' : ''; ?>>Terbuka (Menerima Usulan)</option>
                                <option value="0" <?php echo ($cfg['status_form_buka'] ?? '1') === '0' ? 'selected' : ''; ?>>Ditutup Sementara</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Nama Kepala Perpustakaan</label>
                            <input type="text" name="kepala_perpustakaan" value="<?php echo failibEsc($cfg['kepala_perpustakaan']); ?>"
                                class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">NIP/NBM Kepala Perpustakaan</label>
                            <input type="text" name="nip_kepala_perpustakaan" value="<?php echo failibEsc($cfg['nip_kepala_perpustakaan']); ?>"
                                class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Nama Dekan FAI UMKLA</label>
                            <input type="text" name="dekan_fai" value="<?php echo failibEsc($cfg['dekan_fai']); ?>"
                                class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">NIDN/NBM Dekan FAI UMKLA</label>
                            <input type="text" name="nidn_dekan_fai" value="<?php echo failibEsc($cfg['nidn_dekan_fai']); ?>"
                                class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Informasi Pengantar di Halaman Formulir</label>
                        <textarea name="info_pengadaan" rows="2"
                            class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none"><?php echo failibEsc($cfg['info_pengadaan']); ?></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit"
                            class="px-5 py-2.5 rounded-xl bg-teal-900 hover:bg-teal-800 text-amber-200 text-xs font-extrabold cursor-pointer shadow-xs transition">
                            Simpan Pengaturan Portal
                        </button>
                    </div>
                </form>

                <!-- Bagian 2: Ubah Password Akun Admin -->
                <form action="index.php" method="POST" class="bg-amber-50/50 border border-amber-200/80 rounded-2xl p-5 space-y-4">
                    <?php echo failibCsrfField(); ?>
                    <input type="hidden" name="action" value="ubah_password_admin">

                    <div class="flex items-center justify-between pb-2 border-b border-amber-200/70">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-amber-600 text-white text-xs font-extrabold flex items-center justify-center">2</span>
                            <h4 class="text-xs font-extrabold uppercase tracking-wider text-teal-950">Keamanan &amp; Ubah Password Admin</h4>
                        </div>
                        <span class="text-[11px] font-mono font-bold text-teal-900 bg-white border border-amber-200 px-2 py-0.5 rounded">
                            User: <?php echo failibEsc($admin['username'] ?? 'admin'); ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Nama Tampilan Admin</label>
                            <input type="text" name="nama_lengkap_admin" value="<?php echo failibEsc($admin['nama_lengkap'] ?? 'Administrator Perpustakaan FAI UMKLA'); ?>"
                                class="w-full px-3.5 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Password Saat Ini / Lama <span class="text-rose-500">*</span></label>
                            <input type="password" name="password_lama" required placeholder="Masukkan password saat ini..."
                                class="w-full px-3.5 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Password Baru <span class="text-rose-500">*</span></label>
                            <input type="password" name="password_baru" required minlength="6" placeholder="Minimal 6 karakter..."
                                class="w-full px-3.5 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Konfirmasi Password Baru <span class="text-rose-500">*</span></label>
                            <input type="password" name="konfirmasi_password" required minlength="6" placeholder="Ulangi password baru..."
                                class="w-full px-3.5 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-teal-700 focus:outline-none">
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" onclick="tutupModalPengaturan()"
                            class="px-4 py-2 rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold cursor-pointer">
                            Tutup Panel
                        </button>
                        <button type="submit"
                            class="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-extrabold cursor-pointer shadow-xs transition">
                            Simpan Password Baru
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function ubahStatusCepat(id, status) {
            document.getElementById('inpStatusCepatId').value = id;
            document.getElementById('inpStatusCepatVal').value = status;
            document.getElementById('formStatusCepat').submit();
        }

        function hapusUsulan(id, judul) {
            if (confirm('Apakah Anda yakin ingin menghapus usulan buku "' + judul + '"?')) {
                document.getElementById('inpHapusId').value = id;
                document.getElementById('formHapusUsulan').submit();
            }
        }

        function toggleSelectAll(master) {
            document.querySelectorAll('.chk-row').forEach(chk => {
                chk.checked = master.checked;
            });
        }

        function konfirmasiBulk() {
            const checked = document.querySelectorAll('.chk-row:checked').length;
            const bulkType = document.querySelector('select[name="bulk_type"]').value;
            if (!bulkType) {
                alert('Pilih jenis aksi massal terlebih dahulu.');
                return false;
            }
            if (checked === 0) {
                alert('Centang minimal 1 baris usulan buku terlebih dahulu.');
                return false;
            }
            if (bulkType === 'hapus') {
                return confirm('Hapus ' + checked + ' baris usulan buku yang dipilih?');
            }
            return true;
        }

        function bukaModalTambah() {
            document.getElementById('modalUsulanTitle').textContent = 'Tambah Usulan Koleksi Buku';
            document.getElementById('modalId').value = '0';
            document.getElementById('modalJudul').value = '';
            document.getElementById('modalPengarang').value = '';
            document.getElementById('modalPenerbit').value = '';
            document.getElementById('modalTahun').value = '';
            document.getElementById('modalHarga').value = '';
            document.getElementById('modalJumlah').value = '1';
            document.getElementById('modalKeterangan').value = '';
            document.getElementById('modalNamaPengusul').value = 'Perpustakaan FAI UMKLA';
            document.getElementById('modalKategori').value = 'Pustakawan';
            document.getElementById('modalMk').value = '';
            document.getElementById('modalIdentitas').value = '';
            document.getElementById('modalKontak').value = '';
            document.getElementById('modalPrioritas').value = 'Reguler';
            document.getElementById('modalStatus').value = 'Disetujui';
            document.getElementById('modalCatatanAdmin').value = '';

            const modal = document.getElementById('modalUsulan');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function bukaModalEdit(btn) {
            const data = JSON.parse(btn.getAttribute('data-item') || '{}');
            document.getElementById('modalUsulanTitle').textContent = 'Edit Usulan Koleksi Buku';
            document.getElementById('modalId').value = data.id || '0';
            document.getElementById('modalJudul').value = data.judul || '';
            document.getElementById('modalPengarang').value = data.pengarang || '';
            document.getElementById('modalPenerbit').value = data.penerbit || '';
            document.getElementById('modalTahun').value = data.tahun_terbit || '';
            const hargaNum = Math.round(parseFloat(data.perkiraan_harga || 0));
            document.getElementById('modalHarga').value = hargaNum > 0 ? hargaNum.toLocaleString('id-ID') : '0';
            document.getElementById('modalJumlah').value = data.jumlah_buku || '1';
            document.getElementById('modalKeterangan').value = data.keterangan || '';
            document.getElementById('modalNamaPengusul').value = data.nama_pengusul || '';
            document.getElementById('modalKategori').value = data.kategori_pengusul || 'Dosen';
            document.getElementById('modalMk').value = data.mata_kuliah_terkait || '';
            document.getElementById('modalIdentitas').value = data.identitas_pengusul || '';
            document.getElementById('modalKontak').value = data.kontak_pengusul || '';
            document.getElementById('modalPrioritas').value = data.prioritas || 'Reguler';
            document.getElementById('modalStatus').value = data.status_usulan || 'Menunggu';
            document.getElementById('modalCatatanAdmin').value = data.catatan_admin || '';

            const modal = document.getElementById('modalUsulan');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function tutupModalUsulan() {
            const modal = document.getElementById('modalUsulan');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function bukaModalPengaturan() {
            const modal = document.getElementById('modalPengaturan');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function tutupModalPengaturan() {
            const modal = document.getElementById('modalPengaturan');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
</body>
</html>

