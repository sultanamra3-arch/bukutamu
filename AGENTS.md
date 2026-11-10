# bukutamu.otomasismpn6keritang.my.id — catatan untuk agen AI

- Jawab pengguna (Pak Sultan Amra) dalam Bahasa Indonesia yang sederhana. Untuk ide baru, jelaskan/beri contoh dulu; jangan buat sebelum pengguna bilang "buat".
- Repo ini disambungkan **langsung** ke folder `/home/bzydmpxm/bukutamu.otomasismpn6keritang.my.id` lewat cPanel Git Version Control (tanpa Deploy). Pasang perubahan: Manage → Pull or Deploy → **Update from Remote**.
- **Jangan pernah commit `config.php`** (ada di `.gitignore`; hanya ada di server). Contoh isinya: `config.contoh.php`. Kredensial database dibaca dari konfigurasi SLiMS (`folder_slims`).
- Tabel SLiMS: hanya SELECT (kecuali tabel milik aplikasi ini sendiri).
- `.user.ini` + `siguru-tambahan.php`: tombol "‹ SiGuru" bila dibuka dari SiGuru dan tanpa efek memantul (permintaan pengguna untuk semua aplikasi).
- Bila cPanel mengubah `.htaccess` di server (mis. ganti versi PHP) dan Update from Remote gagal, jangan ubah `.htaccess` di repo — beri tahu pengguna.
