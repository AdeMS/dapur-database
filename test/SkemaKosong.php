<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database\Test;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

/** Skema tanpa isi, untuk uji pabrik (dibuat tanpa argumen). */
final class SkemaKosong implements SkemaModul
{
    public function nama(): string
    {
        return 'kosong';
    }

    public function paket(): string
    {
        return '';
    }

    public function butuh(): array
    {
        return [];
    }

    public function tabel(KonteksSkema $k): array
    {
        return [];
    }

    public function kolom(KonteksSkema $k): array
    {
        return [];
    }

    public function indeks(KonteksSkema $k): array
    {
        return [];
    }

    public function langkah(KonteksSkema $k): array
    {
        return [];
    }
}
