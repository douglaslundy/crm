<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Descomprime os streams Flate do PDF e junta o texto, para asserções de conteúdo. */
trait TextoDePdf
{
    private function textoDoPdf(string $pdf): string
    {
        $texto = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
            foreach ($m[1] as $bruto) {
                $dados = @gzuncompress($bruto);
                $texto .= $dados === false ? $bruto : $dados;
            }
        }

        return $texto;
    }
}
