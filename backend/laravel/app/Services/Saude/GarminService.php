<?php

namespace App\Services\Saude;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente do sidecar Python do Garmin Connect (docker-compose: `garmin`).
 * Só transporta: a curadoria dos campos acontece no sidecar.
 */
class GarminService
{
    public function configOk(): bool
    {
        return (bool) config('garmin.ativo')
            && filled(config('garmin.base_url'))
            && filled(config('garmin.token'));
    }

    /** Não levanta exceção: a UI usa isto para dizer se precisa refazer o login. */
    public function status(): array
    {
        if (! $this->configOk()) {
            return ['ok' => false, 'autenticado' => false, 'erro' => 'nao_configurado'];
        }

        try {
            $resposta = $this->request()->get('/health');
        } catch (\Throwable) {
            return ['ok' => false, 'autenticado' => false, 'erro' => 'sidecar_indisponivel'];
        }

        return $resposta->successful()
            ? $resposta->json()
            : ['ok' => false, 'autenticado' => false, 'erro' => 'sidecar_indisponivel'];
    }

    /**
     * Atividades no período (datas "Y-m-d", inclusivas).
     *
     * @return list<array<string, mixed>>
     */
    public function atividades(string $de, string $ate): array
    {
        $resposta = $this->request()->get('/atividades', ['de' => $de, 'ate' => $ate]);

        $this->garantirSucesso($resposta);

        return $resposta->json('atividades', []);
    }

    /** Resumo do dia medido pelo relógio, ou null quando o Garmin não tem o dia. */
    public function dia(string $data): ?array
    {
        $resposta = $this->request()->get('/dia', ['data' => $data]);

        $this->garantirSucesso($resposta);

        return $resposta->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('garmin.base_url'))
            ->withHeaders(['X-Garmin-Token' => (string) config('garmin.token')])
            ->timeout((int) config('garmin.timeout'))
            ->acceptJson();
    }

    /** Traduz a falha do sidecar em algo acionável para quem está lendo. */
    private function garantirSucesso(Response $resposta): void
    {
        if ($resposta->successful()) {
            return;
        }

        throw new RuntimeException(match ($resposta->status()) {
            401 => 'Os tokens do Garmin expiraram. Refaça o login: docker compose run --rm -it garmin python auth_cli.py',
            503 => 'O Garmin está limitando as requisições. Tente de novo em alguns minutos.',
            default => 'Não foi possível falar com o Garmin Connect.',
        });
    }
}
