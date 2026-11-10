<?php
/**
 * PENGATURAN BUKU TAMU DIGITAL - Perpustakaan SMPN 6 Keritang
 */
return [
    // PIN petugas untuk mendaftarkan perangkat kios/TV/rekap.
    // Kosong ('') = tanpa PIN: halaman langsung bisa dipakai dari perangkat mana pun.
    // Isi angka (mis. '482913') jika suatu saat ingin mengaktifkan PIN lagi.
    'pin_petugas' => '',

    // Kunci rahasia untuk "mengingat" perangkat. Jangan dibagikan.
    // Jika diganti, semua perangkat harus memasukkan PIN lagi.
    'kunci_rahasia' => 'ISI-KUNCI-ACAK-DI-SERVER',

    // Lokasi folder SLiMS di hosting
    'folder_slims' => '/home/bzydmpxm/public_html',

    // Database: dibaca otomatis dari SLiMS. Isi hanya jika pembacaan otomatis gagal.
    'db_host' => '', 'db_port' => '3306', 'db_nama' => '', 'db_user' => '', 'db_pass' => '',

    // Kunjungan orang yang sama dalam rentang ini tidak dihitung ulang (menit)
    'jeda_menit' => 60,

    // Tanyakan keperluan kunjungan? (true/false) dan pilihannya
    'tanya_keperluan' => true,
    'keperluan' => ['Membaca', 'Meminjam / Mengembalikan', 'Mengerjakan Tugas', 'Belajar Kelompok', 'Lainnya'],

    // Nama sekolah untuk kop rekap
    'nama_sekolah'   => 'SMP Negeri 6 Keritang',
    'alamat_sekolah' => 'Sencalang, Kec. Keritang, Kab. Indragiri Hilir, Riau',   // periksa & sesuaikan,
];
