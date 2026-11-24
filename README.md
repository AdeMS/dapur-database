# Database Dapur AdeMS

Paket untuk [Dapur AdeMS](https://github.com/AdeMS/dapur) yang memasang dan memperbarui tabel database **semua
modul** dengan satu perintah:

```bash
php dapur pasang
```

Setiap modul yang menyimpan data (misalnya [adems/dapur-user](https://github.com/AdeMS/dapur-user)) membawa daftar
tabelnya sendiri. Saat modul dipasang atau diperbarui, `php dapur pasang` membuat tabel yang belum ada, menambahkan
kolom baru ke tabel lama, dan menjalankan langkah perubahan data yang dibawa versi baru. Data yang sudah ada tidak
pernah dihapus.

## Kebutuhan

- Dapur AdeMS 2.10 atau lebih baru (untuk perintah `php dapur pasang`)
- Koneksi database yang sudah diatur di `config/autoload/database.local.php`: MariaDB/MySQL atau SQLite
  (PostgreSQL menyusul)

Paket ini biasanya ikut terpasang otomatis sebagai kebutuhan modul lain. Untuk memasangnya sendiri, tulis di
`composer.local.json`, lalu jalankan `composer update`:

```json
{
    "require": {
        "adems/dapur-database": "^1.0"
    }
}
```

## Memakai perintah pasang

Jalankan dari folder inti Dapur:

```bash
php dapur pasang              # tampilkan rencana, lalu tanya [y/N] sebelum dijalankan
php dapur pasang --periksa    # hanya tampilkan rencana; database tidak diubah
php dapur pasang --ya         # jalankan tanpa bertanya, misalnya dari skrip
php dapur pasang user         # hanya modul user (beserta modul yang dibutuhkannya)
```

Contoh keluaran pada instalasi baru:

```
Dapur (adems/dapur-database 1.0.0)
  Tabel xyz_dapur_skema                    akan dibuat
Modul user (adems/dapur-user 2.5.0)
  Tabel xyz_user                           akan dibuat
  Tabel xyz_user_akses                     akan dibuat
  ...
  Langkah user:2.0-peran-admin             akan dijalankan

Jalankan 12 perubahan di atas? [y/N]:
```

Pertanyaan `[y/N]` hanya muncul bila perintah dijalankan di terminal. Dari skrip (misalnya cron atau skrip
pembaruan), tambahkan `--ya`; tanpa itu perintah berhenti tanpa mengubah apa pun.

Kode keluar perintah:

| Kode | Arti |
|---|---|
| `0` | Database sudah siap (tidak ada perubahan, atau semua perubahan berhasil) |
| `1` | Gagal, atau dibatalkan |
| `2` | (`--periksa`) Ada perubahan yang belum dijalankan |

Dengan paket [adems/dapur-multiwebsite](https://github.com/AdeMS/dapur-multiwebsite), tambahkan
`--website=contoh.com` untuk memasang tabel modul pilihan website itu, di database website itu (bila website itu
memakai database sendiri).

### Aturan keamanan

- **Tidak pernah** menghapus tabel atau kolom, dan tidak mengubah tipe kolom.
- Kolom baru selalu boleh kosong atau punya nilai bawaan, jadi aman ditambahkan ke tabel yang sudah berisi data.
- Langkah data dijalankan masing-masing **sekali saja** dan dicatat di tabel `dapur_skema`. Setiap langkah berjalan
  dalam transaksi: bila gagal, perubahan langkah itu dibatalkan.
- Dua `pasang` tidak bisa berjalan bersamaan pada database yang sama (MariaDB/MySQL memakai `GET_LOCK`, SQLite
  memakai file kunci di sebelah file databasenya).
- Bila satu perubahan gagal, perintah berhenti. Perubahan sebelumnya tetap tersimpan, jadi cukup perbaiki
  penyebabnya lalu jalankan `pasang` lagi.

> Di MariaDB/MySQL, perintah yang mengubah struktur tabel (`CREATE TABLE`, `ALTER TABLE`) tidak bisa dibatalkan
> dengan transaksi. Gunakan `--periksa` lebih dulu bila ragu, dan buat cadangan database sebelum memperbarui
> instalasi yang sudah dipakai.

### Awalan nama tabel

Bila satu database dipakai bersama aplikasi lain, beri awalan nama tabel di
`config/autoload/database.local.php` (sebelum `pasang` pertama kali):

```php
return [
    'db'       => [/* koneksi */],
    'database' => ['awalan_tabel' => 'xyz_'],   // xyz_user, xyz_user_akses, xyz_dapur_skema, ...
];
```

Awalan hanya boleh berisi huruf, angka, dan garis bawah, paling panjang 32 karakter. Pengaturan lama dapur-user
`'user' => ['awalan_tabel' => ...]` tetap dibaca bila `database.awalan_tabel` belum ditulis.

## Membuat skema untuk modul Anda

Bagian ini untuk pembuat modul. Konsepnya mirip *migration* di framework lain, tetapi lebih sederhana: modul
menulis **keadaan akhir** tabelnya, lalu `pasang` membandingkannya dengan isi database dan hanya menambahkan yang
kurang.

### 1. Tulis kelas skema

Buat kelas yang menerapkan `AdeMS\Dapur\Database\SkemaModul`. Contoh modul toko:

```php
namespace Toko;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

final class TokoSkema implements SkemaModul
{
    public function nama(): string
    {
        return 'toko';                         // dipakai di laporan dan di `php dapur pasang toko`
    }

    public function paket(): string
    {
        return 'contoh/toko';                  // nama paket Composer, untuk menampilkan versinya
    }

    public function butuh(): array
    {
        return ['user'];                       // tabel toko merujuk tabel user, jadi user dipasang lebih dulu
    }

    public function tabel(KonteksSkema $k): array
    {
        $barang = $k->tabel('toko_barang');    // ikut awalan instalasi, mis. xyz_toko_barang
        $user   = $k->tabel('user');

        return match ($k->platform) {
            KonteksSkema::MYSQL  => [
                $barang => "CREATE TABLE {$k->kutip($barang)} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    nama VARCHAR(190) NOT NULL,
                    dibuat_oleh BIGINT UNSIGNED NULL,
                    FOREIGN KEY (dibuat_oleh) REFERENCES {$k->kutip($user)} (id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            KonteksSkema::SQLITE => [
                $barang => "CREATE TABLE {$k->kutip($barang)} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nama TEXT NOT NULL,
                    dibuat_oleh INTEGER NULL REFERENCES {$k->kutip($user)} (id) ON DELETE SET NULL
                )",
            ],
        };
    }

    public function kolom(KonteksSkema $k): array
    {
        // Kolom yang datang di versi 1.1, sesudah tabelnya dibuat di 1.0.
        return [$k->tabel('toko_barang') => ['harga' => 'INTEGER NOT NULL DEFAULT 0']];
    }

    public function indeks(KonteksSkema $k): array
    {
        $nama = $k->tabel('toko_barang_nama');

        return [$nama => "CREATE INDEX {$k->kutip($nama)} ON {$k->kutip($k->tabel('toko_barang'))} (nama)"];
    }

    public function langkah(KonteksSkema $k): array
    {
        return [
            // Dijalankan sekali saja. Nama langkah tidak boleh diganti sesudah dirilis.
            '1.1-harga-awal' => static function (KonteksSkema $k): ?string {
                $k->jalankan('UPDATE ' . $k->kutip($k->tabel('toko_barang')) . ' SET harga = 1000 WHERE harga = 0');

                return 'harga awal diisi';     // keterangan untuk laporan; boleh null
            },
        ];
    }
}
```

| Bagian | Isi | Kapan dijalankan |
|---|---|---|
| `tabel()` | `CREATE TABLE` setiap tabel, berurutan (tabel yang dirujuk foreign key lebih dulu) | Bila tabel belum ada |
| `kolom()` | Kolom yang datang sesudah tabelnya pertama kali dibuat | Bila kolom belum ada di tabel lama |
| `indeks()` | `CREATE INDEX` yang ditambahkan sesudah tabelnya dibuat | Bila indeks belum ada |
| `langkah()` | Perubahan data sekali jalan | Bila belum tercatat di `dapur_skema` |

Saat menambah kolom di versi baru, tulis kolom itu **di dua tempat**: di `CREATE TABLE` (untuk instalasi baru) dan
di `kolom()` (untuk instalasi lama). Untuk tabel yang baru dibuat, `kolom()` dilewati karena kolomnya sudah ada di
`CREATE TABLE`.

### 2. Daftarkan di ConfigProvider modul

```php
public function __invoke(): array
{
    return [
        'dapur' => ['skema' => [TokoSkema::class]],
        // ...
    ];
}
```

`php dapur pasang` membaca semua kelas di `dapur.skema` dari konfigurasi yang sudah digabung, jadi modul yang
dipilih website lewat `website.php` ikut terpasang dengan `--website=`. Kelas skema dibuat dengan `new` tanpa
argumen, kecuali kelas itu terdaftar di container.

### 3. Uji dengan SQLite

`Pemasang` bisa dipakai langsung di uji PHPUnit dengan database SQLite di memori:

```php
use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\Pemasang;
use Laminas\Db\Adapter\Adapter;

$db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
$p  = new Pemasang(new KonteksSkema($db, 'xyz_'), [new UserSkema(), new TokoSkema()]);

$p->jalankan($p->rencana());
self::assertSame([], $p->rencana());   // sesudah dipasang, tidak ada perubahan tersisa
```

`Pemasang::perluDiperbarui()` menjawab apakah masih ada perubahan yang belum dijalankan, misalnya untuk
menampilkan peringatan di dashboard.

## Kelas

| Kelas | Kegunaan |
|---|---|
| `SkemaModul` | Interface skema satu modul |
| `KonteksSkema` | Koneksi, jenis database (`MySQL`, `SQLite`, `PostgreSQL`), awalan tabel, dan pembantu `tabel()`, `kutip()`, `jalankan()`, `baris()` |
| `Pemasang` | Menyusun rencana perubahan (`rencana()`) dan menjalankannya (`jalankan()`) |
| `Perubahan` | Satu perubahan dalam rencana: tabel, kolom, indeks, atau langkah |
| `Perintah` | Perintah baris `pasang` (dipanggil `php dapur pasang`) |
| `PabrikPemasang` | Menyusun `Pemasang` dari container aplikasi |

## Uji

```bash
composer install
composer test
```

## Lisensi

BSD-3-Clause. Lihat [LICENSE.md](LICENSE.md).
