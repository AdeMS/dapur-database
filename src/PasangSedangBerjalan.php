<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Database;

use RuntimeException;

/** `pasang` lain sedang berjalan untuk database yang sama; tidak ada yang diubah. */
final class PasangSedangBerjalan extends RuntimeException
{
}
