<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/** Erro de uma linha do CSV: o `ImportadorCsv` captura e nunca deixa escapar para o controller. */
final class ErroDeImportacao extends RuntimeException {}
