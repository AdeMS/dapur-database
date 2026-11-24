<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use Composer\InstalledVersions;
use Throwable;

/**
 * Perintah `php dapur pasang [<modul>] [--periksa] [--ya]`.
 *
 * Tanpa --ya, rencana perubahan ditampilkan lalu ditanyakan [y/N] (hanya bila dijalankan di terminal). --periksa hanya
 * menampilkan rencana. Kode keluar: 0 = database sudah siap, 1 = gagal atau dibatalkan, 2 = (--periksa) ada perubahan
 * yang belum dijalankan.
 */
final class Perintah
{
    public const KELUAR_SIAP  = 0;
    public const KELUAR_GAGAL = 1;
    public const KELUAR_PERLU = 2;

    private int $lebar = 40;

    /**
     * @param (callable(string): string)|null $tanya pertanyaan ke pengguna di terminal; null bila tidak di terminal
     */
    public function __construct(
        private readonly Pemasang $pemasang,
        private $tanya = null,
    ) {
    }

    /** @param list<string> $argumen argumen sesudah nama perintah `pasang` */
    public function jalankan(array $argumen): int
    {
        $periksa = in_array('--periksa', $argumen, true);
        $ya      = in_array('--ya', $argumen, true);
        $bebas   = array_values(array_filter($argumen, static fn (string $a): bool => ! str_starts_with($a, '--')));
        $nama    = $bebas[0] ?? null;

        try {
            $rencana = $this->pemasang->rencana($nama);
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage());
        }

        if ($rencana === []) {
            echo 'Database sudah siap. Tidak ada perubahan.' . PHP_EOL;

            return self::KELUAR_SIAP;
        }

        // Lebar kolom uraian mengikuti uraian terpanjang, agar laporan tetap rata untuk nama tabel/kolom yang panjang.
        $this->lebar = max(40, ...array_map(static fn (Perubahan $p): int => strlen($p->uraian()), $rencana));

        if ($periksa || ! $ya) {
            $this->tampilkanRencana($rencana);
        }

        if ($periksa) {
            printf('%s%d perubahan belum dijalankan. Jalankan: php dapur pasang%s', PHP_EOL, count($rencana), PHP_EOL);

            return self::KELUAR_PERLU;
        }

        if (! $ya) {
            if ($this->tanya === null) {
                return $this->gagal('Tambahkan --ya untuk menjalankan perubahan di atas di luar terminal.');
            }

            $jawab = strtolower(trim(($this->tanya)(sprintf('%sJalankan %d perubahan di atas? [y/N]: ', PHP_EOL, count($rencana)))));
            if (! in_array($jawab, ['y', 'ya'], true)) {
                echo 'Dibatalkan. Database tidak diubah.' . PHP_EOL;

                return self::KELUAR_GAGAL;
            }

            echo PHP_EOL;
        }

        $modulTerakhir = null;
        try {
            $this->pemasang->jalankan($rencana, function (Perubahan $p, ?string $keterangan) use (&$modulTerakhir): void {
                if ($p->modul !== $modulTerakhir) {
                    echo $this->judulModul($p->modul) . PHP_EOL;
                    $modulTerakhir = $p->modul;
                }

                printf('  %-' . $this->lebar . 's %s%s%s', $p->uraian(), $p->hasil(), $keterangan === null || $keterangan === '' ? '' : ': ' . $keterangan, PHP_EOL);
            });
        } catch (PasangSedangBerjalan $e) {
            return $this->gagal($e->getMessage());
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage() . PHP_EOL . 'Perubahan yang sudah berhasil tetap tersimpan; perbaiki penyebabnya lalu jalankan pasang lagi.');
        }

        printf('%sDatabase sudah siap. %d perubahan dijalankan.%s', PHP_EOL, count($rencana), PHP_EOL);

        return self::KELUAR_SIAP;
    }

    /** @param list<Perubahan> $rencana */
    private function tampilkanRencana(array $rencana): void
    {
        $modulTerakhir = null;
        foreach ($rencana as $p) {
            if ($p->modul !== $modulTerakhir) {
                echo $this->judulModul($p->modul) . PHP_EOL;
                $modulTerakhir = $p->modul;
            }

            printf('  %-' . $this->lebar . 's akan %s%s', $p->uraian(), $p->hasil(), PHP_EOL);
        }
    }

    private function judulModul(string $nama): string
    {
        if ($nama === Pemasang::MODUL_DAPUR) {
            return 'Dapur' . self::versi('adems/dapur-database');
        }

        $skema = $this->pemasang->modul()[$nama] ?? null;

        return 'Modul ' . $nama . ($skema === null ? '' : self::versi($skema->paket()));
    }

    private static function versi(string $paket): string
    {
        if ($paket === '' || ! InstalledVersions::isInstalled($paket)) {
            return '';
        }

        return sprintf(' (%s %s)', $paket, InstalledVersions::getPrettyVersion($paket) ?? '');
    }

    private function gagal(string $pesan): int
    {
        fwrite(STDERR, 'Gagal: ' . $pesan . PHP_EOL);

        return self::KELUAR_GAGAL;
    }
}
