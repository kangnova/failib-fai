<?php
/**
 * File: failib/admin/cetak.php
 * Cetak Lembar Resmi Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helper.php';

failibRequireAdmin('login.php');
$cfg = failibGetSettings($pdo);

$filterStatus   = trim($_GET['status'] ?? '');
$filterKategori = trim($_GET['kategori'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');

$where  = [];
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
$sql .= " ORDER BY id ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$bulanIndo = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];
$tanggalCetak = date('j') . ' ' . ($bulanIndo[(int)date('n')] ?? '') . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Formulir Pengadaan Usulan Koleksi Buku — Perpustakaan FAI UMKLA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; padding: 0 !important; }
            .print-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; padding: 0 !important; }
        }
        table.tbl-resmi { width: 100%; border-collapse: collapse; }
        table.tbl-resmi th, table.tbl-resmi td { border: 1px solid #0F172A; padding: 6px 8px; font-size: 11px; }
        table.tbl-resmi th { background-color: #F1F5F9; font-weight: 800; text-transform: uppercase; }
    </style>
</head>
<body class="bg-slate-200 min-h-screen py-6 px-4">

    <div class="max-w-5xl mx-auto mb-4 flex items-center justify-between no-print bg-teal-950 text-white px-5 py-3 rounded-2xl shadow-lg">
        <div class="text-xs font-bold">
            Pratinjau Cetak Dokumen Resmi Pengadaan Buku (<?php echo count($rows); ?> Judul)
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-extrabold cursor-pointer">
                Cetak / Simpan PDF
            </button>
            <a href="index.php" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold">
                Kembali
            </a>
        </div>
    </div>

    <div class="print-sheet max-w-5xl mx-auto bg-white p-8 sm:p-10 rounded-2xl shadow-xl border border-slate-300 text-slate-900">
        <!-- Kop Surat Perpustakaan FAI UMKLA -->
        <div class="border-b-4 border-double border-slate-900 pb-3 mb-5 text-center">
            <p class="text-xs font-bold uppercase tracking-widest text-slate-700">UNIVERSITAS MUHAMMADIYAH KLATEN</p>
            <h1 class="text-lg font-extrabold uppercase tracking-wide">FAKULTAS AGAMA ISLAM — PERPUSTAKAAN FAI (FAILib)</h1>
            <p class="text-[11px] text-slate-600">Jl. Ir. Soekarno KM. 01 Buntalan, Klaten Tengah, Kabupaten Klaten, Jawa Tengah</p>
        </div>

        <div class="text-center mb-5">
            <h2 class="text-sm sm:text-base font-extrabold uppercase underline tracking-wide">
                FORMULIR PENGADAAN USULAN KOLEKSI BUKU PERPUSTAKAAN FAI UMKLA
            </h2>
            <p class="text-xs font-semibold text-slate-700 mt-1">
                Periode Pengadaan: <?php echo failibEsc($cfg['periode_pengadaan']); ?>
                <?php if ($filterStatus !== ''): ?>
                    • Status: <?php echo failibEsc($filterStatus); ?>
                <?php endif; ?>
            </p>
        </div>

        <table class="tbl-resmi">
            <thead>
                <tr>
                    <th style="width: 36px; text-align: center;">NO</th>
                    <th>JUDUL</th>
                    <th>PENGARANG</th>
                    <th>PENERBIT</th>
                    <th style="width: 120px; text-align: right;">PERKIRAAN HARGA</th>
                    <th>KETERANGAN (JUMLAH BUKU YANG DIMINTA)</th>
                    <th style="width: 125px; text-align: right;">ESTIMASI TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">Belum ada data usulan buku.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $totalEks = 0;
                    $totalNominal = 0;
                    foreach ($rows as $i => $r):
                        $sub = (float)$r['perkiraan_harga'] * (int)$r['jumlah_buku'];
                        $totalEks += (int)$r['jumlah_buku'];
                        $totalNominal += $sub;
                    ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $i + 1; ?></td>
                            <td>
                                <strong><?php echo failibEsc($r['judul']); ?></strong>
                                <?php if (!empty($r['mata_kuliah_terkait'])): ?>
                                    <div style="font-size: 10px; color: #475569;">MK: <?php echo failibEsc($r['mata_kuliah_terkait']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo failibEsc($r['pengarang']); ?></td>
                            <td>
                                <?php echo failibEsc($r['penerbit']); ?>
                                <?php if (!empty($r['tahun_terbit'])): ?>
                                    (<?php echo failibEsc($r['tahun_terbit']); ?>)
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-family: monospace;"><?php echo failibRupiah($r['perkiraan_harga']); ?></td>
                            <td>
                                <strong><?php echo (int)$r['jumlah_buku']; ?> Buku</strong> — <?php echo failibEsc($r['keterangan']); ?>
                                <div style="font-size: 10px; color: #64748B;">Pengusul: <?php echo failibEsc($r['nama_pengusul']); ?> (<?php echo failibEsc($r['kategori_pengusul']); ?>)</div>
                            </td>
                            <td style="text-align: right; font-family: monospace; font-weight: bold;"><?php echo failibRupiah($sub); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background-color: #F8FAFC; font-weight: bold;">
                        <td colspan="5" style="text-align: right;">TOTAL KESELURUHAN USULAN PENGADAAN:</td>
                        <td><?php echo count($rows); ?> Judul (<?php echo $totalEks; ?> Eksemplar Buku)</td>
                        <td style="text-align: right; font-family: monospace;"><?php echo failibRupiah($totalNominal); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Blok Tanda Tangan -->
        <div class="grid grid-cols-2 gap-8 mt-10 text-xs">
            <div class="text-center">
                <p>Mengetahui,</p>
                <p class="font-bold">Dekan Fakultas Agama Islam UMKLA</p>
                <div class="h-20"></div>
                <p class="font-extrabold underline"><?php echo failibEsc($cfg['dekan_fai']); ?></p>
                <p>NIDN/NBM. <?php echo failibEsc($cfg['nidn_dekan_fai']); ?></p>
            </div>
            <div class="text-center">
                <p>Klaten, <?php echo $tanggalCetak; ?></p>
                <p class="font-bold">Pengelola / Kepala Perpustakaan FAI UMKLA</p>
                <div class="h-20"></div>
                <p class="font-extrabold underline"><?php echo failibEsc($cfg['kepala_perpustakaan']); ?></p>
                <p>NIP/NBM. <?php echo failibEsc($cfg['nip_kepala_perpustakaan']); ?></p>
            </div>
        </div>
    </div>
</body>
</html>

