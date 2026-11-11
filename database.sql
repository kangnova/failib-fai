-- =========================================================================
-- Skema Database Terpusat Modul FAILib (Perpustakaan FAI UMKLA)
-- Database: fai_umkla (Terintegrasi dengan src/Config/database.php)
-- =========================================================================

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
    `keterangan` TEXT DEFAULT NULL COMMENT 'Keterangan jumlah buku yang diminta / mata kuliah / alasan pengadaan',
    `nama_pengusul` VARCHAR(150) NOT NULL DEFAULT 'Civitas Akademika FAI',
    `kategori_pengusul` ENUM('Dosen', 'Mahasiswa', 'Tendik', 'Prodi / Unit', 'Pustakawan', 'Lainnya') NOT NULL DEFAULT 'Dosen',
    `identitas_pengusul` VARCHAR(100) DEFAULT NULL COMMENT 'NIDN / NIM / Unit / Prodi',
    `kontak_pengusul` VARCHAR(50) DEFAULT NULL COMMENT 'Nomor WhatsApp / Email',
    `mata_kuliah_terkait` VARCHAR(150) DEFAULT NULL,
    `prioritas` ENUM('Reguler', 'Penting', 'Sangat Mendesak') NOT NULL DEFAULT 'Reguler',
    `status_usulan` ENUM('Menunggu', 'Disetujui', 'Dalam Pengadaan', 'Tersedia', 'Ditolak') NOT NULL DEFAULT 'Menunggu',
    `catatan_admin` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status_usulan` (`status_usulan`),
    INDEX `idx_kode_usulan` (`kode_usulan`),
    INDEX `idx_judul` (`judul`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failib_admin` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(60) NOT NULL UNIQUE,
    `nama_lengkap` VARCHAR(120) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'admin',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failib_pengaturan` (
    `kunci` VARCHAR(80) PRIMARY KEY,
    `nilai` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

