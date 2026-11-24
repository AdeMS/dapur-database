<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database\Test;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\Pemasang;
use AdeMS\Dapur\Database\PasangSedangBerjalan;
use AdeMS\Dapur\Database\Perubahan;
use InvalidArgumentException;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PemasangTest extends TestCase
{
    private Adapter $db;
    private KonteksSkema $k;

    protected function setUp(): void
    {
        $this->db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $this->k  = new KonteksSkema($this->db, 'uji_');
    }

    private function toko(): SkemaUji
    {
        $s                = new SkemaUji('toko');
        $s->tabel         = ['toko_barang' => 'id INTEGER PRIMARY KEY, nama TEXT NOT NULL'];
        $s->langkah['1.0-isi'] = static function (KonteksSkema $k): string {
            $k->jalankan('INSERT INTO ' . $k->kutip($k->tabel('toko_barang')) . " (nama) VALUES ('sabun')");

            return '1 barang';
        };

        return $s;
    }

    /** @return list<string> */
    private function uraian(Pemasang $p, ?string $modul = null): array
    {
        return array_map(static fn (Perubahan $x): string => $x->uraian(), $p->rencana($modul));
    }

    public function testDatabaseKosong(): void
    {
        $p = new Pemasang($this->k, [$this->toko()]);

        self::assertSame(['Tabel uji_dapur_skema', 'Tabel uji_toko_barang', 'Langkah toko:1.0-isi'], $this->uraian($p));
        self::assertTrue($p->perluDiperbarui());

        $laporan = [];
        $p->jalankan($p->rencana(), static function (Perubahan $x, ?string $ket) use (&$laporan): void {
            $laporan[] = $x->uraian() . ' ' . $x->hasil() . ($ket === null ? '' : ": {$ket}");
        });

        self::assertSame([
            'Tabel uji_dapur_skema dibuat',
            'Tabel uji_toko_barang dibuat',
            'Langkah toko:1.0-isi dijalankan: 1 barang',
        ], $laporan);
        self::assertSame([], $p->rencana());
        self::assertFalse($p->perluDiperbarui());
        self::assertCount(1, $this->k->baris('SELECT * FROM uji_toko_barang'));
        self::assertSame([['modul' => 'toko', 'langkah' => '1.0-isi']], $this->k->baris('SELECT modul, langkah FROM uji_dapur_skema'));
    }

    public function testKolomDanIndeksBaruDitambahkanKeTabelLama(): void
    {
        $toko = $this->toko();
        $p    = new Pemasang($this->k, [$toko]);
        $p->jalankan($p->rencana());

        // Versi berikutnya: kolom dan indeks baru.
        $toko->kolom  = ['toko_barang' => ['harga' => 'INTEGER NOT NULL DEFAULT 0', 'nama' => 'TEXT NOT NULL']];
        $toko->indeks = ['toko_barang_nama' => 'toko_barang(nama)'];

        self::assertSame(['Kolom uji_toko_barang.harga', 'Indeks uji_toko_barang_nama'], $this->uraian($p));
        $p->jalankan($p->rencana());
        self::assertSame([], $p->rencana());

        // Data lama tetap ada, kolom baru memakai nilai bawaan.
        self::assertEquals([['nama' => 'sabun', 'harga' => 0]], $this->k->baris('SELECT nama, harga FROM uji_toko_barang'));
    }

    public function testKolomTidakDitambahkanKeTabelYangBaruDibuat(): void
    {
        $toko        = $this->toko();
        $toko->tabel = ['toko_barang' => 'id INTEGER PRIMARY KEY, nama TEXT NOT NULL, harga INTEGER NOT NULL DEFAULT 0'];
        $toko->kolom = ['toko_barang' => ['harga' => 'INTEGER NOT NULL DEFAULT 0']];

        self::assertNotContains('Kolom uji_toko_barang.harga', $this->uraian(new Pemasang($this->k, [$toko])));
    }

    public function testKebutuhanMenentukanUrutan(): void
    {
        $user        = new SkemaUji('user');
        $user->tabel = ['user' => 'id INTEGER PRIMARY KEY'];
        $uang        = new SkemaUji('uang', ['user']);
        $uang->tabel = ['uang_buku' => 'id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES uji_user(id)'];

        // uang didaftarkan lebih dulu, tetapi user dipasang lebih dulu.
        $p = new Pemasang($this->k, [$uang, $user]);
        self::assertSame(['user', 'uang'], array_keys($p->modul()));
        self::assertSame(['Tabel uji_dapur_skema', 'Tabel uji_user', 'Tabel uji_uang_buku'], $this->uraian($p));

        // Satu modul saja: modul yang dibutuhkannya ikut.
        self::assertSame(['Tabel uji_dapur_skema', 'Tabel uji_user'], $this->uraian($p, 'user'));
        self::assertSame(['Tabel uji_dapur_skema', 'Tabel uji_user', 'Tabel uji_uang_buku'], $this->uraian($p, 'uang'));
    }

    public function testKebutuhanTidakTerpasangAtauBerputar(): void
    {
        try {
            new Pemasang($this->k, [new SkemaUji('uang', ['user'])]);
            self::fail('Seharusnya ditolak.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("membutuhkan modul 'user'", $e->getMessage());
        }

        $this->expectExceptionMessage('berputar');
        new Pemasang($this->k, [new SkemaUji('a', ['b']), new SkemaUji('b', ['a'])]);
    }

    public function testModulTidakDikenalDanNamaTidakSah(): void
    {
        $p = new Pemasang($this->k, [$this->toko()]);
        try {
            $p->rencana('uang');
            self::fail('Seharusnya ditolak.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("Modul 'uang' tidak terpasang", $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        new Pemasang($this->k, [new SkemaUji('dapur')]);
    }

    public function testModulGandaCukupSekali(): void
    {
        $p = new Pemasang($this->k, [$this->toko(), $this->toko()]);
        self::assertCount(1, $p->modul());
    }

    public function testLangkahGagalDibatalkanDanBisaDiulang(): void
    {
        $toko                     = $this->toko();
        $gagal                    = true;
        $toko->langkah['1.1-ubah'] = static function (KonteksSkema $k) use (&$gagal): ?string {
            $k->jalankan('UPDATE ' . $k->kutip($k->tabel('toko_barang')) . " SET nama = 'sampo'");
            if ($gagal) {
                throw new RuntimeException('putus');
            }

            return null;
        };

        $p = new Pemasang($this->k, [$toko]);
        try {
            $p->jalankan($p->rencana());
            self::fail('Seharusnya gagal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Langkah toko:1.1-ubah gagal: putus', $e->getMessage());
        }

        // Perubahan langkah yang gagal dibatalkan; perubahan sebelumnya tetap.
        self::assertSame([['nama' => 'sabun']], $this->k->baris('SELECT nama FROM uji_toko_barang'));
        self::assertSame(['Langkah toko:1.1-ubah'], $this->uraian($p));

        $gagal = false;
        $p->jalankan($p->rencana());
        self::assertSame([['nama' => 'sampo']], $this->k->baris('SELECT nama FROM uji_toko_barang'));
        self::assertSame([], $p->rencana());
    }

    public function testAwalanTidakSah(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KonteksSkema($this->db, 'uji-');
    }

    public function testKunciFileSqlite(): void
    {
        $folder = sys_get_temp_dir() . '/dapur-database-uji-' . bin2hex(random_bytes(4));
        mkdir($folder);
        $file = $folder . '/dapur.sqlite';
        $k    = new KonteksSkema(new Adapter(['driver' => 'Pdo_Sqlite', 'database' => $file]));
        $p    = new Pemasang($k, [$this->toko()], $file . '.pasang.lock');

        $lain = fopen($file . '.pasang.lock', 'c');
        self::assertNotFalse($lain);
        flock($lain, LOCK_EX);
        try {
            $p->jalankan($p->rencana());
            self::fail('Seharusnya ditolak karena pasang lain sedang berjalan.');
        } catch (PasangSedangBerjalan $e) {
            self::assertStringContainsString('sedang berjalan', $e->getMessage());
        } finally {
            flock($lain, LOCK_UN);
            fclose($lain);
        }

        $p->jalankan($p->rencana());
        self::assertSame([], $p->rencana());

        unlink($file . '.pasang.lock');
        unlink($file);
        rmdir($folder);
    }
}
