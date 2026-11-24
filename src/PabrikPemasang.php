<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Menyusun Pemasang dari container aplikasi: koneksi database, awalan tabel, dan skema semua modul yang terdaftar di
 * `'dapur' => ['skema' => [...]]`.
 */
final class PabrikPemasang
{
    public static function dariContainer(ContainerInterface $c): Pemasang
    {
        $config = $c->get('config');
        $config = is_array($config) ? $config : [];
        $db     = $c->get(Adapter::class);
        assert($db instanceof Adapter);

        return new Pemasang(
            new KonteksSkema($db, self::awalan($config)),
            self::skema($c, $config),
            self::fileKunci($config),
        );
    }

    /**
     * Awalan tabel instalasi: `'database' => ['awalan_tabel' => ...]`. Pengaturan lama dapur-user
     * `'user' => ['awalan_tabel' => ...]` tetap dibaca bila yang baru belum ada.
     *
     * @param array<mixed> $config
     */
    public static function awalan(array $config): string
    {
        $awalan = $config['database']['awalan_tabel'] ?? $config['user']['awalan_tabel'] ?? '';

        return is_string($awalan) ? $awalan : '';
    }

    /**
     * @param array<mixed> $config
     * @return list<SkemaModul>
     */
    private static function skema(ContainerInterface $c, array $config): array
    {
        $daftar = $config['dapur']['skema'] ?? [];
        $hasil  = [];
        foreach (is_array($daftar) ? $daftar : [] as $kelas) {
            if (! is_string($kelas) || ! class_exists($kelas)) {
                throw new RuntimeException('Kelas skema tidak ditemukan: ' . (is_string($kelas) ? $kelas : get_debug_type($kelas)) . '.');
            }

            $skema = $c->has($kelas) ? $c->get($kelas) : new $kelas();
            if (! $skema instanceof SkemaModul) {
                throw new RuntimeException("{$kelas} bukan " . SkemaModul::class . '.');
            }

            $hasil[] = $skema;
        }

        return $hasil;
    }

    /**
     * File kunci untuk SQLite, di sebelah file databasenya; null untuk database lain atau database di memori.
     *
     * @param array<mixed> $config
     */
    private static function fileKunci(array $config): ?string
    {
        $db     = $config['db'] ?? [];
        $driver = strtolower((string) ($db['driver'] ?? ''));
        $file   = (string) ($db['database'] ?? '');

        return str_contains($driver, 'sqlite') && $file !== '' && $file !== ':memory:' ? $file . '.pasang.lock' : null;
    }
}
