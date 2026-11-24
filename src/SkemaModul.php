<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

/**
 * Struktur database satu modul Dapur (mis. user, uang). Modul mendaftarkan kelas ini di ConfigProvider-nya:
 *
 *     'dapur' => ['skema' => [UserSkema::class]],
 *
 * lalu `php dapur pasang` membuat tabel, kolom, dan indeks yang belum ada, serta menjalankan langkah data yang belum
 * pernah dijalankan. Perintah itu tidak pernah menghapus tabel atau kolom, dan tidak mengubah tipe kolom.
 *
 * Nama tabel sebaiknya memakai $k->tabel('<modul>_<nama>'), agar ikut awalan tabel instalasi (mis. adems_user_akses).
 */
interface SkemaModul
{
    /** Nama pendek modul, mis. 'user'. Dipakai di laporan, di catatan langkah, dan untuk `php dapur pasang <nama>`. */
    public function nama(): string;

    /** Nama paket Composer modul (mis. 'adems/dapur-user'), untuk menampilkan versinya; '' bila bukan paket. */
    public function paket(): string;

    /**
     * Modul yang harus dipasang lebih dulu, mis. ['user'] untuk modul yang tabelnya merujuk tabel user.
     *
     * @return list<string>
     */
    public function butuh(): array;

    /**
     * Tabel modul, berurutan: tabel yang dirujuk foreign key ditulis lebih dulu. Tabel yang sudah ada tidak diubah.
     *
     * @return array<string, string> nama tabel => perintah CREATE TABLE
     */
    public function tabel(KonteksSkema $k): array;

    /**
     * Kolom yang datang di versi sesudah tabelnya pertama kali dibuat. Kolom baru harus boleh kosong (NULL) atau punya
     * nilai bawaan, karena ditambahkan ke tabel yang sudah berisi data.
     *
     * @return array<string, array<string, string>> nama tabel => [nama kolom => definisi kolom]
     */
    public function kolom(KonteksSkema $k): array;

    /**
     * Indeks yang ditambahkan sesudah tabelnya dibuat. Nama indeks harus unik di seluruh database, jadi sebaiknya
     * memakai awalan tabel juga.
     *
     * @return array<string, string> nama indeks => perintah CREATE INDEX
     */
    public function indeks(KonteksSkema $k): array;

    /**
     * Langkah data yang dijalankan sekali saja, mis. mengubah nilai lama ke nilai baru. Nama langkah tidak boleh
     * diganti setelah dirilis, karena dipakai untuk mencatat bahwa langkah itu sudah dijalankan. Setiap langkah
     * berjalan dalam transaksi; sebaiknya aman bila diulang (mis. instalasi yang sudah diperbarui dengan cara lama).
     * Callable menerima KonteksSkema dan boleh mengembalikan keterangan singkat hasilnya (mis. "3 akses diubah").
     *
     * @return array<string, callable(KonteksSkema): (string|null)> nama langkah => callable
     */
    public function langkah(KonteksSkema $k): array;
}
