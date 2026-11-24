<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database\Test;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\PabrikPemasang;
use AdeMS\Dapur\Database\Pemasang;
use AdeMS\Dapur\Database\Perintah;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class PerintahTest extends TestCase
{
    private KonteksSkema $k;
    private Pemasang $pemasang;

    protected function setUp(): void
    {
        $this->k        = new KonteksSkema(new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']), 'uji_');
        $toko           = new SkemaUji('toko');
        $toko->tabel    = ['toko_barang' => 'id INTEGER PRIMARY KEY'];
        $this->pemasang = new Pemasang($this->k, [$toko]);
    }

    /**
     * @param list<string> $argumen
     * @return array{int, string}
     */
    private function jalankan(array $argumen, ?callable $tanya = null): array
    {
        ob_start();
        $kode = (new Perintah($this->pemasang, $tanya))->jalankan($argumen);

        return [$kode, (string) ob_get_clean()];
    }

    public function testPeriksaTidakMengubahApaPun(): void
    {
        [$kode, $keluaran] = $this->jalankan(['--periksa']);

        self::assertSame(Perintah::KELUAR_PERLU, $kode);
        self::assertStringContainsString('Tabel uji_toko_barang', $keluaran);
        self::assertStringContainsString('akan dibuat', $keluaran);
        self::assertStringContainsString('2 perubahan belum dijalankan', $keluaran);
        self::assertTrue($this->pemasang->perluDiperbarui());
    }

    public function testTanpaTerminalWajibYa(): void
    {
        [$kode] = $this->jalankan([]);
        self::assertSame(Perintah::KELUAR_GAGAL, $kode);
        self::assertTrue($this->pemasang->perluDiperbarui());

        [$kode, $keluaran] = $this->jalankan(['--ya']);
        self::assertSame(Perintah::KELUAR_SIAP, $kode);
        self::assertStringContainsString('Tabel uji_toko_barang', $keluaran);
        self::assertStringContainsString('2 perubahan dijalankan', $keluaran);
        self::assertFalse($this->pemasang->perluDiperbarui());

        [$kode, $keluaran] = $this->jalankan(['--ya']);
        self::assertSame(Perintah::KELUAR_SIAP, $kode);
        self::assertStringContainsString('Tidak ada perubahan', $keluaran);
    }

    public function testDitanyaDiTerminal(): void
    {
        [$kode, $keluaran] = $this->jalankan([], static fn (string $pesan): string => 'n');
        self::assertSame(Perintah::KELUAR_GAGAL, $kode);
        self::assertStringContainsString('Dibatalkan', $keluaran);
        self::assertTrue($this->pemasang->perluDiperbarui());

        $ditanya           = '';
        [$kode, $keluaran] = $this->jalankan([], static function (string $pesan) use (&$ditanya): string {
            $ditanya = $pesan;

            return 'y';
        });
        self::assertSame(Perintah::KELUAR_SIAP, $kode);
        self::assertStringContainsString('Jalankan 2 perubahan di atas? [y/N]', $ditanya);
        self::assertFalse($this->pemasang->perluDiperbarui());
    }

    public function testModulTidakDikenal(): void
    {
        [$kode] = $this->jalankan(['uang', '--periksa']);
        self::assertSame(Perintah::KELUAR_GAGAL, $kode);
    }

    public function testPabrikMembacaKonfigurasi(): void
    {
        $db        = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $container = new class ($db) implements ContainerInterface {
            public function __construct(private Adapter $db)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    'config'       => [
                        'db'    => ['driver' => 'Pdo_Sqlite', 'database' => ':memory:'],
                        'user'  => ['awalan_tabel' => 'lama_'],
                        'dapur' => ['skema' => [SkemaKosong::class, SkemaKosong::class]],
                    ],
                    Adapter::class => $this->db,
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $p = PabrikPemasang::dariContainer($container);
        self::assertSame(['kosong'], array_keys($p->modul()));
        self::assertSame('lama_dapur_skema', $p->tabelCatatan());

        self::assertSame('baru_', PabrikPemasang::awalan(['database' => ['awalan_tabel' => 'baru_'], 'user' => ['awalan_tabel' => 'lama_']]));
        self::assertSame('', PabrikPemasang::awalan([]));
    }
}
