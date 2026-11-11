<?php
/**
 * File: failib/admin/login.php
 * Halaman Login Admin & Pustakawan FAILib — Perpustakaan FAI UMKLA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helper.php';

if (failibCurrentAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!failibVerifyCsrf()) {
        $error = 'Sesi keamanan kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username dan Password wajib diisi.';
        } else {
            $admin = failibAttemptLogin($pdo, $username, $password);
            if ($admin) {
                session_regenerate_id(true);
                $_SESSION['failib_admin'] = $admin;
                header('Location: index.php');
                exit;
            } else {
                $error = 'Username atau Password tidak sesuai.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin Perpustakaan — FAILib FAI UMKLA</title>
    <link rel="icon" href="../../global/favicon/favicon.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at 85% 15%, rgba(245, 158, 11, 0.22) 0%, transparent 40%),
                radial-gradient(circle at 15% 85%, rgba(20, 184, 166, 0.2) 0%, transparent 45%),
                linear-gradient(135deg, #0B2B2C 0%, #0F3D3E 50%, #164E63 100%);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-3xl shadow-2xl border border-white/20 overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-teal-950 via-teal-900 to-teal-800 px-7 py-6 text-white border-b-4 border-amber-500">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-amber-500/20 border border-amber-400/40 flex items-center justify-center text-amber-300 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
                    </svg>
                </div>
                <div>
                    <span class="text-[10px] font-extrabold uppercase tracking-widest text-amber-300">PORTAL PUSTAKAWAN &amp; ADMIN</span>
                    <h1 class="text-lg font-extrabold leading-tight">FAILib — FAI UMKLA</h1>
                    <p class="text-xs text-teal-100/80">Kelola Pengadaan Usulan Koleksi Buku</p>
                </div>
            </div>
        </div>

        <form action="login.php" method="POST" class="p-7 space-y-5">
            <?php echo failibCsrfField(); ?>

            <?php if ($error !== ''): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold px-4 py-3 rounded-xl flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                    <span><?php echo failibEsc($error); ?></span>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-700 mb-1.5">
                    Username Admin
                </label>
                <input type="text" name="username" required autofocus placeholder="Masukkan username admin..."
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none transition">
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-wider text-slate-700 mb-1.5">
                    Password
                </label>
                <input type="password" name="password" required placeholder="Masukkan password..."
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-700 focus:bg-white focus:outline-none transition">
            </div>

            <button type="submit"
                class="w-full py-3.5 px-5 rounded-xl bg-gradient-to-r from-teal-900 to-teal-800 hover:from-teal-800 hover:to-teal-700 text-amber-200 font-extrabold text-sm shadow-lg transition cursor-pointer flex items-center justify-center gap-2">
                <span>Masuk ke Panel Admin FAILib</span>
                <svg class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
            </button>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <a href="../index.php" class="font-bold text-teal-800 hover:text-amber-700 inline-flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                    <span>Kembali ke Form Usulan</span>
                </a>
                <span class="text-[11px] text-slate-400">Terintegrasi SSO Admin FAI</span>
            </div>
        </form>
    </div>
</body>
</html>

