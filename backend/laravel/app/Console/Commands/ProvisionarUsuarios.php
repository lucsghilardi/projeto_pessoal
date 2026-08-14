<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Console\Command;

/**
 * Cobre as contas criadas antes do provisionamento automático existir. As
 * migrations de seed só rodaram uma vez, então quem entrou depois ficou sem
 * categorias, conta bancária e fichas de treino.
 *
 * Seguro de repetir: o serviço é idempotente.
 */
class ProvisionarUsuarios extends Command
{
    protected $signature = 'usuarios:provisionar
                            {--user= : E-mail ou id de um usuário específico; sem isso, provisiona todos}';

    protected $description = 'Cria os dados iniciais (categorias, conta, instituições e fichas de treino) de quem ainda não tem';

    public function handle(UserProvisioningService $provisioning): int
    {
        $alvo = (string) ($this->option('user') ?? '');

        $usuarios = $alvo === ''
            ? User::query()->orderBy('id')->get()
            : User::query()
                ->when(
                    ctype_digit($alvo),
                    fn ($query) => $query->whereKey((int) $alvo),
                    fn ($query) => $query->whereRaw('lower(email) = ?', [mb_strtolower(trim($alvo))]),
                )
                ->get();

        if ($usuarios->isEmpty()) {
            $this->error($alvo === '' ? 'Nenhum usuário cadastrado.' : "Usuário \"{$alvo}\" não encontrado.");

            return self::FAILURE;
        }

        foreach ($usuarios as $usuario) {
            $provisioning->provisionar($usuario);
            $this->line("  <info>✓</info> {$usuario->email}");
        }

        $this->info("{$usuarios->count()} usuário(s) provisionado(s).");

        return self::SUCCESS;
    }
}
