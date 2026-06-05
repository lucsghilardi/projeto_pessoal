<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    private const CACHE_KEY = 'exchange.usd_brl';

    // Usado quando a cotação online está indisponível (apenas como aproximação).
    private const FALLBACK_RATE = 5.00;

    /**
     * Retorna a cotação de venda do dólar (USD -> BRL).
     *
     * @return array{rate: float, available: bool, fetched_at: ?string}
     */
    public function usdToBrl(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)
                ->retry(2, 200)
                ->get('https://economia.awesomeapi.com.br/json/last/USD-BRL');

            $bid = (float) data_get($response->json(), 'USDBRL.bid');

            if ($response->successful() && $bid > 0) {
                $data = [
                    'rate' => round($bid, 4),
                    'available' => true,
                    'fetched_at' => now()->toIso8601String(),
                ];

                Cache::put(self::CACHE_KEY, $data, now()->addHour());

                return $data;
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao buscar cotacao USD-BRL: '.$e->getMessage());
        }

        return [
            'rate' => self::FALLBACK_RATE,
            'available' => false,
            'fetched_at' => null,
        ];
    }
}
