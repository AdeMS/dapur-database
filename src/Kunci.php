<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

/**
 * Mencegah dua `pasang` berjalan bersamaan pada database yang sama. MySQL/MariaDB memakai GET_LOCK (berlaku di seluruh
 * server, jadi namanya memuat nama database); SQLite memakai kunci file di sebelah file database; PostgreSQL memakai
 * advisory lock.
 */
final class Kunci
{
    /** @var resource|null */
    private $file = null;

    private bool $terkunci = false;

    public function __construct(
        private readonly KonteksSkema $k,
        private readonly ?string $fileKunci = null,
    ) {
    }

    /** true bila kunci didapat; false bila `pasang` lain sedang berjalan. */
    public function ambil(): bool
    {
        switch ($this->k->platform) {
            case KonteksSkema::MYSQL:
                $hasil          = $this->k->baris('SELECT GET_LOCK(?, 0) AS kunci', [$this->nama()]);
                $this->terkunci = (int) ($hasil[0]['kunci'] ?? 0) === 1;

                return $this->terkunci;

            case KonteksSkema::POSTGRESQL:
                $hasil          = $this->k->baris('SELECT pg_try_advisory_lock(hashtext(?)) AS kunci', [$this->nama()]);
                $this->terkunci = in_array($hasil[0]['kunci'] ?? false, [true, 't', 1, '1'], true);

                return $this->terkunci;

            default:
                // SQLite: kunci file. Bila file kunci tidak bisa dibuat (mis. database di memori), lanjut tanpa kunci.
                if ($this->fileKunci === null) {
                    return true;
                }

                $file = @fopen($this->fileKunci, 'c');
                if ($file === false) {
                    return true;
                }

                if (! flock($file, LOCK_EX | LOCK_NB)) {
                    fclose($file);

                    return false;
                }

                $this->file     = $file;
                $this->terkunci = true;

                return true;
        }
    }

    public function lepas(): void
    {
        if (! $this->terkunci) {
            return;
        }

        match ($this->k->platform) {
            KonteksSkema::MYSQL      => $this->k->baris('SELECT RELEASE_LOCK(?) AS lepas', [$this->nama()]),
            KonteksSkema::POSTGRESQL => $this->k->baris('SELECT pg_advisory_unlock(hashtext(?)) AS lepas', [$this->nama()]),
            default                  => null,
        };

        if (is_resource($this->file)) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
            $this->file = null;
        }

        $this->terkunci = false;
    }

    private function nama(): string
    {
        $database = (string) ($this->k->db->getCurrentSchema() ?: '');

        // Nama kunci MySQL maksimal 64 karakter.
        return substr('dapur_pasang_' . $database . '_' . $this->k->awalan, 0, 64);
    }
}
