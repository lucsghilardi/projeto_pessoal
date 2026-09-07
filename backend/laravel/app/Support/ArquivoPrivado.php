<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega um arquivo do disco privado (comprovante, proposta de consórcio, foto
 * de refeição) declarando o tipo pela EXTENSÃO GRAVADA, nunca pelo conteúdo.
 *
 * A `Storage::response()` crua resolve o Content-Type com o finfo, que olha os
 * primeiros bytes: um "comprovante.jpg" com HTML dentro volta como text/html e
 * o navegador executa o script na origem do painel. Como o upload é aceito pela
 * extensão que o usuário escolheu, quem decide o Content-Type na saída tem de
 * ser a lista fechada abaixo — e o que não estiver nela desce como anexo opaco.
 */
class ArquivoPrivado
{
    /** Extensão gravada → Content-Type devolvido (e exibível no navegador). */
    private const TIPOS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
    ];

    public static function resposta(string $disco, string $path): StreamedResponse
    {
        $tipo = self::TIPOS[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        return Storage::disk($disco)->response(
            $path,
            null,
            [
                'Content-Type' => $tipo ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                // Mesmo cinto que o Laravel põe na rota /storage assinada.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            ],
            // Extrato/CSV não tem por que ser renderizado: vira download.
            $tipo !== null ? 'inline' : 'attachment',
        );
    }
}
