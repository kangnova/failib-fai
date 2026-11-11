<?php
/**
 * File: failib/admin/export_excel.php
 * Export Excel (.xls) Formulir Pengadaan Usulan Koleksi Buku Perpustakaan FAI UMKLA
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

$filename = 'Usulan_Koleksi_Buku_Perpustakaan_FAI_UMKLA_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 11pt; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #1E293B; padding: 6px 8px; vertical-align: middle; }
        th { background-color: #0F3D3E; color: #FFFFFF; font-weight: bold; text-align: center; }
        .num { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <h3 style="margin-bottom: 4px;">FORMULIR PENGADAAN USULAN KOLEKSI BUKU PERPUSTAKAAN FAI UMKLA</h3>
    <p style="margin-top: 0; margin-bottom: 12px;">
        Periode: <strong><?php echo failibEsc($cfg['periode_pengadaan']); ?></strong> |
        Tanggal Unduh: <?php echo date('d/m/Y H:i'); ?> WIB
    </p>

    <table>
        <thead>
            <tr>
                <th>NO</th>
                <th>KODE USULAN</th>
                <th>JUDUL</th>
                <th>PENGARANG</th>
                <th>PENERBIT</th>
                <th>PERKIRAAN HARGA (RP)</th>
                <th>JUMLAH BUKU DIMINTA</th>
                <th>KETERANGAN (JUMLAH BUKU YANG DIMINTA)</th>
                <th>ESTIMASI SUBTOTAL (RP)</th>
                <th>NAMA PENGUSUL</th>
                <th>KATEGORI</th>
                <th>MATA KULIAH TERKAIT</th>
                <th>STATUS PENGADAAN</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $totalEks = 0;
            $totalHarga = 0;
            foreach ($rows as $idx => $r):
                $sub = (float)$r['perkiraan_harga'] * (int)$r['jumlah_buku'];
                $totalEks += (int)$r['jumlah_buku'];
                $totalHarga += $sub;
            ?>
                <tr>
                    <td class="center"><?php echo $idx + 1; ?></td>
                    <td class="center"><?php echo failibEsc($r['kode_usulan']); ?></td>
                    <td><?php echo failibEsc($r['judul']); ?></td>
                    <td><?php echo failibEsc($r['pengarang']); ?></td>
                    <td><?php echo failibEsc($r['penerbit']); ?></td>
                    <td class="num"><?php echo number_format((float)$r['perkiraan_harga'], 0, ',', '.'); ?></td>
                    <td class="center"><?php echo (int)$r['jumlah_buku']; ?></td>
                    <td><?php echo failibEsc($r['keterangan']); ?></td>
                    <td class="num"><?php echo number_format($sub, 0, ',', '.'); ?></td>
                    <td><?php echo failibEsc($r['nama_pengusul']); ?></td>
                    <td class="center"><?php echo failibEsc($r['kategori_pengusul']); ?></td>
                    <td><?php echo failibEsc($r['mata_kuliah_terkait'] ?? '-'); ?></td>
                    <td class="center"><?php echo failibEsc($r['status_usulan']); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold; background-color: #FEF3C7;">
                <td colspan="6" class="num">TOTAL REKAPITULASI PENGADAAN BUKU:</td>
                <td class="center"><?php echo $totalEks; ?> Eks</td>
                <td></td>
                <td class="num">Rp <?php echo number_format($totalHarga, 0, ',', '.'); ?></td>
                <td colspan="4"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>

