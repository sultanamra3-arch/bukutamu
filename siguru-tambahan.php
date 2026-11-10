<?php
/* Tambahan dari SiGuru (11 Nov 2026) — dipasang lewat .user.ini (auto_append_file), TIDAK mengubah kode aplikasi ini.
 * 1. Tanpa efek memantul/meregang saat digulir mentok atas/bawah.
 * 2. Tombol "‹ SiGuru" (pojok kiri bawah) bila halaman dibuka dari SiGuru (?dari=siguru). Tidak tampil di layar kios buku tamu.
 * Hanya ditambahkan pada halaman HTML biasa. Aman dihapus kapan saja (hapus juga baris di .user.ini). */
(function (): void {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || http_response_code() >= 300) return;
    foreach (headers_list() as $h) {
        $h = strtolower($h);
        if ((str_starts_with($h, 'content-type:') && !str_contains($h, 'text/html')) || str_starts_with($h, 'content-disposition:') || str_starts_with($h, 'location:')) return;
    }
    echo '<style>html,body{overscroll-behavior:none}.ke-siguru-tbh{position:fixed;left:12px;bottom:calc(14px + env(safe-area-inset-bottom));z-index:2147483000;padding:8px 14px;border-radius:99px;background:#F2C230;color:#1B1D5E;font:700 14px/1 system-ui,sans-serif;text-decoration:none;box-shadow:0 3px 10px rgba(0,0,0,.25)}@media print{.ke-siguru-tbh{display:none}}</style>'
        . '<script>(function(){try{var q=new URLSearchParams(location.search);if(q.get("dari")==="siguru")sessionStorage.setItem("dariSiguru","1");'
        . 'if(sessionStorage.getItem("dariSiguru")!=="1"||q.get("p")==="layar")return;var a=document.createElement("a");a.className="ke-siguru-tbh";'
        . 'a.href="https://guru.otomasismpn6keritang.my.id/?p=beranda";a.textContent="‹ SiGuru";(document.body||document.documentElement).appendChild(a);}catch(e){}})();</script>';
})();
