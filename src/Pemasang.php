<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use Closure;
use Laminas\Db\Metadata\Source\Factory;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Memasang dan memperbarui tabel semua modul: membuat tabel, kolom, dan indeks yang belum ada, lalu menjalankan langkah
 * data yang belum pernah dijalankan. Tidak pernah menghapus tabel atau kolom, dan tidak mengubah tipe kolom.
 *
 * Langkah yang sudah dijalankan dicatat di tabel `<awalan>dapur_skema`.
 */
final class Pemasang
{
    /** Nama modul untuk tabel catatan milik paket ini. */
    public const MODUL_DAPUR = 'dapur';

    /** @var array<string, SkemaModul> nama modul => skema, berurutan menurut kebutuhan */
    private readonly array $modul;

    /**
     * @param iterable<SkemaModul> $modul
     */
    public function __construct(
        private readonly KonteksSkema $k,
        iterable $modul,
        private readonly ?string $fileKunci = null,
    ) {
        $this->modul = self::urutkan($modul);
    }

    /** Nama tabel catatan langkah. */
    public function tabelCatatan(): string
    {
        return $this->k->tabel('dapur_skema');
    }

    /** @return array<string, SkemaModul> */
    public function modul(): array
    {
        return $this->modul;
    }

    /**
     * Daftar perubahan yang belum dijalankan. Dengan $nama, hanya modul itu beserta modul yang dibutuhkannya.
     *
     * @return list<Perubahan>
     */
    public function rencana(?string $nama = null): array
    {
        $pilihan = $nama === null ? array_keys($this->modul) : $this->denganKebutuhan($nama);

        $metadata = Factory::createSourceFromAdapter($this->k->db);
        $tabelAda = $metadata->getTableNames();
        $sudah    = $this->langkahSudah($tabelAda);
        $rencana  = [];

        // Tabel catatan milik paket ini selalu dibuat lebih dulu.
        if (! in_array($this->tabelCatatan(), $tabelAda, true)) {
            $rencana[] = new Perubahan(self::MODUL_DAPUR, Perubahan::TABEL, $this->tabelCatatan(), $this->sqlCatatan());
        }

        foreach ($pilihan as $n) {
            $skema   = $this->modul[$n];
            $tabelku = $skema->tabel($this->k);

            foreach ($tabelku as $tabel => $sql) {
                if (! in_array($tabel, $tabelAda, true)) {
                    $rencana[] = new Perubahan($n, Perubahan::TABEL, $tabel, $sql);
                }
            }

            foreach ($skema->kolom($this->k) as $tabel => $daftarKolom) {
                // Tabel yang baru dibuat sudah memuat kolom terbarunya di CREATE TABLE.
                if (! in_array($tabel, $tabelAda, true)) {
                    if (! isset($tabelku[$tabel])) {
                        throw new RuntimeException("Modul {$n}: kolom untuk tabel {$tabel} yang tidak ada di skema mana pun.");
                    }

                    continue;
                }

                $kolomAda = $metadata->getColumnNames($tabel);
                foreach ($daftarKolom as $kolom => $definisi) {
                    if (! in_array($kolom, $kolomAda, true)) {
                        $rencana[] = new Perubahan(
                            $n,
                            Perubahan::KOLOM,
                            $tabel . '.' . $kolom,
                            'ALTER TABLE ' . $this->k->kutip($tabel) . ' ADD COLUMN ' . $this->k->kutip($kolom) . ' ' . $definisi,
                        );
                    }
                }
            }

            foreach ($skema->indeks($this->k) as $indeks => $sql) {
                if (! $this->indeksAda($indeks)) {
                    $rencana[] = new Perubahan($n, Perubahan::INDEKS, $indeks, $sql);
                }
            }

            foreach ($skema->langkah($this->k) as $langkah => $jalankan) {
                if (! isset($sudah[$n . ':' . $langkah])) {
                    $rencana[] = new Perubahan($n, Perubahan::LANGKAH, $langkah, '', Closure::fromCallable($jalankan));
                }
            }
        }

        return $rencana;
    }

    /** Apakah ada perubahan yang belum dijalankan (mis. untuk peringatan di dashboard). */
    public function perluDiperbarui(): bool
    {
        return $this->rencana() !== [];
    }

    /**
     * Menjalankan rencana. $lapor dipanggil sesudah setiap perubahan berhasil, dengan keterangan hasil langkah data.
     * Berhenti di perubahan pertama yang gagal; perubahan sebelumnya tetap tersimpan, jadi pasang bisa diulang.
     *
     * @param list<Perubahan> $rencana
     * @param (callable(Perubahan, string|null): void)|null $lapor
     */
    public function jalankan(array $rencana, ?callable $lapor = null): void
    {
        $kunci = new Kunci($this->k, $this->fileKunci);
        if (! $kunci->ambil()) {
            throw new PasangSedangBerjalan('Perintah pasang lain sedang berjalan untuk database ini. Coba lagi sebentar lagi; database tidak diubah.');
        }

        try {
            foreach ($rencana as $p) {
                $keterangan = $p->jenis === Perubahan::LANGKAH ? $this->jalankanLangkah($p) : $this->jalankanSql($p);
                if ($lapor !== null) {
                    $lapor($p, $keterangan);
                }
            }
        } finally {
            $kunci->lepas();
        }
    }

    private function jalankanSql(Perubahan $p): ?string
    {
        $this->k->jalankan($p->sql);

        return null;
    }

    private function jalankanLangkah(Perubahan $p): ?string
    {
        assert($p->langkah !== null);
        // Transaksi lewat PDO langsung: perintah DDL di MySQL menutup transaksi dengan sendirinya, dan PDO tahu
        // keadaan sebenarnya, sedangkan penanda transaksi laminas-db tidak.
        $koneksi = $this->k->db->getDriver()->getConnection();
        $koneksi->connect();
        $pdo = $koneksi->getResource();
        assert($pdo instanceof PDO);

        $pdo->beginTransaction();
        try {
            $keterangan = ($p->langkah)($this->k);
            $this->k->jalankan(
                'INSERT INTO ' . $this->k->kutip($this->tabelCatatan()) . ' (modul, langkah, dijalankan_pada) VALUES (?, ?, ?)',
                [$p->modul, $p->nama, gmdate('Y-m-d H:i:s')],
            );
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new RuntimeException("Langkah {$p->modul}:{$p->nama} gagal: " . $e->getMessage(), 0, $e);
        }

        return $keterangan;
    }

    /**
     * @param list<string> $tabelAda
     * @return array<string, true> "modul:langkah" yang sudah dijalankan
     */
    private function langkahSudah(array $tabelAda): array
    {
        if (! in_array($this->tabelCatatan(), $tabelAda, true)) {
            return [];
        }

        $sudah = [];
        foreach ($this->k->baris('SELECT modul, langkah FROM ' . $this->k->kutip($this->tabelCatatan())) as $baris) {
            $sudah[$baris['modul'] . ':' . $baris['langkah']] = true;
        }

        return $sudah;
    }

    private function indeksAda(string $nama): bool
    {
        $baris = match ($this->k->platform) {
            KonteksSkema::MYSQL      => $this->k->baris(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ? LIMIT 1',
                [$nama],
            ),
            KonteksSkema::SQLITE     => $this->k->baris("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?", [$nama]),
            KonteksSkema::POSTGRESQL => $this->k->baris(
                'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
                [$nama],
            ),
            default                  => throw new RuntimeException("Database {$this->k->platform} belum didukung."),
        };

        return $baris !== [];
    }

    private function sqlCatatan(): string
    {
        $tabel = $this->k->kutip($this->tabelCatatan());

        return match ($this->k->platform) {
            KonteksSkema::MYSQL      => "CREATE TABLE {$tabel} (
                modul VARCHAR(64) NOT NULL,
                langkah VARCHAR(100) NOT NULL,
                dijalankan_pada DATETIME NOT NULL,
                PRIMARY KEY (modul, langkah)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            KonteksSkema::SQLITE     => "CREATE TABLE {$tabel} (
                modul TEXT NOT NULL,
                langkah TEXT NOT NULL,
                dijalankan_pada TEXT NOT NULL,
                PRIMARY KEY (modul, langkah)
            )",
            KonteksSkema::POSTGRESQL => "CREATE TABLE {$tabel} (
                modul VARCHAR(64) NOT NULL,
                langkah VARCHAR(100) NOT NULL,
                dijalankan_pada TIMESTAMP NOT NULL,
                PRIMARY KEY (modul, langkah)
            )",
            default                  => throw new RuntimeException("Database {$this->k->platform} belum didukung."),
        };
    }

    /**
     * Modul $nama beserta semua modul yang dibutuhkannya, berurutan.
     *
     * @return list<string>
     */
    private function denganKebutuhan(string $nama): array
    {
        if (! isset($this->modul[$nama])) {
            throw new RuntimeException("Modul '{$nama}' tidak terpasang. Modul yang terpasang: " . implode(', ', array_keys($this->modul)) . '.');
        }

        $perlu  = [];
        $antrean = [$nama];
        while ($antrean !== []) {
            $n = array_pop($antrean);
            if (isset($perlu[$n])) {
                continue;
            }

            $perlu[$n] = true;
            array_push($antrean, ...$this->modul[$n]->butuh());
        }

        return array_values(array_filter(array_keys($this->modul), static fn (string $n): bool => isset($perlu[$n])));
    }

    /**
     * Mengurutkan modul agar modul yang dibutuhkan dipasang lebih dulu (urutan pendaftaran dipertahankan bila bisa).
     *
     * @param iterable<SkemaModul> $modul
     * @return array<string, SkemaModul>
     */
    private static function urutkan(iterable $modul): array
    {
        $semua = [];
        foreach ($modul as $skema) {
            $nama = $skema->nama();
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $nama) !== 1 || $nama === self::MODUL_DAPUR) {
                throw new RuntimeException("Nama modul '{$nama}' tidak sah (huruf kecil, angka, garis bawah; bukan 'dapur').");
            }

            if (isset($semua[$nama])) {
                // Modul yang sama bisa terdaftar dua kali (mis. lewat dua ConfigProvider); cukup sekali.
                continue;
            }

            $semua[$nama] = $skema;
        }

        $urut   = [];
        $proses = [];
        $kunjungi = static function (string $nama, array $jalur) use (&$kunjungi, &$urut, &$proses, $semua): void {
            if (isset($urut[$nama])) {
                return;
            }

            if (isset($proses[$nama])) {
                throw new RuntimeException('Kebutuhan modul berputar: ' . implode(' -> ', [...$jalur, $nama]) . '.');
            }

            $proses[$nama] = true;
            foreach ($semua[$nama]->butuh() as $butuh) {
                if (! isset($semua[$butuh])) {
                    throw new RuntimeException("Modul '{$nama}' membutuhkan modul '{$butuh}', tetapi modul itu tidak terpasang.");
                }

                $kunjungi($butuh, [...$jalur, $nama]);
            }

            $urut[$nama] = $semua[$nama];
        };

        foreach (array_keys($semua) as $nama) {
            $kunjungi($nama, []);
        }

        return $urut;
    }
}
