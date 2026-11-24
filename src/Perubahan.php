<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use Closure;

/** Satu perubahan yang akan dijalankan `pasang`: tabel baru, kolom baru, indeks baru, atau langkah data. */
final class Perubahan
{
    public const TABEL   = 'tabel';
    public const KOLOM   = 'kolom';
    public const INDEKS  = 'indeks';
    public const LANGKAH = 'langkah';

    /**
     * @param string $nama nama tabel, "tabel.kolom", nama indeks, atau nama langkah
     * @param string $sql perintah SQL untuk tabel, kolom, dan indeks ('' untuk langkah)
     * @param (Closure(KonteksSkema): (string|null))|null $langkah
     */
    public function __construct(
        public readonly string $modul,
        public readonly string $jenis,
        public readonly string $nama,
        public readonly string $sql = '',
        public readonly ?Closure $langkah = null,
    ) {
    }

    /** Uraian untuk laporan, mis. "Tabel adems_user_profil". */
    public function uraian(): string
    {
        return match ($this->jenis) {
            self::TABEL   => 'Tabel ' . $this->nama,
            self::KOLOM   => 'Kolom ' . $this->nama,
            self::INDEKS  => 'Indeks ' . $this->nama,
            default       => 'Langkah ' . $this->modul . ':' . $this->nama,
        };
    }

    /** Kata kerja hasil, mis. "dibuat". */
    public function hasil(): string
    {
        return match ($this->jenis) {
            self::TABEL, self::INDEKS => 'dibuat',
            self::KOLOM               => 'ditambahkan',
            default                   => 'dijalankan',
        };
    }
}
