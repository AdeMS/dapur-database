<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database\Test;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

/** Skema modul contoh untuk uji (SQLite). Isinya bisa diubah per uji. */
final class SkemaUji implements SkemaModul
{
    /** @var array<string, string> tabel dasar => kolom CREATE TABLE */
    public array $tabel = [];

    /** @var array<string, array<string, string>> */
    public array $kolom = [];

    /** @var array<string, string> nama indeks dasar => "tabel dasar(kolom)" */
    public array $indeks = [];

    /** @var array<string, callable(KonteksSkema): (string|null)> */
    public array $langkah = [];

    /** @param list<string> $butuh */
    public function __construct(
        private readonly string $nama,
        private readonly array $butuh = [],
    ) {
    }

    public function nama(): string
    {
        return $this->nama;
    }

    public function paket(): string
    {
        return '';
    }

    public function butuh(): array
    {
        return $this->butuh;
    }

    public function tabel(KonteksSkema $k): array
    {
        $hasil = [];
        foreach ($this->tabel as $dasar => $isi) {
            $hasil[$k->tabel($dasar)] = 'CREATE TABLE ' . $k->kutip($k->tabel($dasar)) . " ({$isi})";
        }

        return $hasil;
    }

    public function kolom(KonteksSkema $k): array
    {
        $hasil = [];
        foreach ($this->kolom as $dasar => $kolom) {
            $hasil[$k->tabel($dasar)] = $kolom;
        }

        return $hasil;
    }

    public function indeks(KonteksSkema $k): array
    {
        $hasil = [];
        foreach ($this->indeks as $nama => $sasaran) {
            [$dasar, $kolom] = explode('(', rtrim($sasaran, ')'), 2);
            $hasil[$k->tabel($nama)] = 'CREATE INDEX ' . $k->kutip($k->tabel($nama)) . ' ON ' . $k->kutip($k->tabel($dasar)) . " ({$kolom})";
        }

        return $hasil;
    }

    public function langkah(KonteksSkema $k): array
    {
        return $this->langkah;
    }
}
