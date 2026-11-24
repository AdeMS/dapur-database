<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use InvalidArgumentException;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\ResultSet\ResultSetInterface;

/** Database yang sedang dipasang: koneksi, jenis database, dan awalan nama tabel. */
final class KonteksSkema
{
    public const MYSQL      = 'MySQL';
    public const SQLITE     = 'SQLite';
    public const POSTGRESQL = 'PostgreSQL';

    /** Jenis database menurut laminas-db: 'MySQL' (juga MariaDB), 'SQLite', atau 'PostgreSQL'. */
    public readonly string $platform;

    public function __construct(
        public readonly Adapter $db,
        public readonly string $awalan = '',
    ) {
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $awalan) !== 1) {
            throw new InvalidArgumentException('Awalan tabel hanya boleh huruf, angka, dan garis bawah (maksimal 32).');
        }

        $this->platform = $db->getPlatform()->getName();
    }

    /** Nama tabel lengkap dengan awalan, mis. tabel('user_akses') = 'adems_user_akses'. */
    public function tabel(string $nama): string
    {
        return $this->awalan . $nama;
    }

    /** Nama tabel, kolom, atau indeks yang diberi tanda kutip sesuai jenis database. */
    public function kutip(string $nama): string
    {
        return $this->db->getPlatform()->quoteIdentifier($nama);
    }

    /**
     * Menjalankan satu perintah SQL dengan parameter.
     *
     * @param list<mixed> $parameter
     */
    public function jalankan(string $sql, array $parameter = []): ResultInterface|ResultSetInterface
    {
        return $parameter === []
            ? $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE)
            : $this->db->query($sql, $parameter);
    }

    /**
     * Baris hasil SELECT.
     *
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    public function baris(string $sql, array $parameter = []): array
    {
        $hasil = [];
        foreach ($this->jalankan($sql, $parameter) as $baris) {
            $hasil[] = is_array($baris) ? $baris : (array) $baris->getArrayCopy();
        }

        return $hasil;
    }
}
