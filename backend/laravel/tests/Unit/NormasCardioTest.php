<?php

namespace Tests\Unit;

use App\Support\Saude\NormasCardio;
use PHPUnit\Framework\TestCase;

class NormasCardioTest extends TestCase
{
    public function test_percentil_de_vo2max_usa_a_faixa_etaria_certa(): void
    {
        // Mediana masculina da faixa 30-39 é 42,4 (Cooper/ACSM).
        $mediana = NormasCardio::percentilVo2max(42.4, 'M', 38);
        $this->assertSame(50, $mediana['percentil']);
        $this->assertSame(42.4, $mediana['mediana']);

        // 49,0 fica logo abaixo do p75 (49,2) da mesma faixa.
        $acima = NormasCardio::percentilVo2max(49.0, 'M', 38);
        $this->assertGreaterThan(70, $acima['percentil']);
        $this->assertLessThan(75, $acima['percentil']);
        $this->assertSame('Acima da média', $acima['classificacao']);

        // A faixa muda aos 40: o mesmo VO2max vale mais.
        $quarentao = NormasCardio::percentilVo2max(49.0, 'M', 42);
        $this->assertGreaterThan($acima['percentil'], $quarentao['percentil']);
    }

    public function test_percentil_fora_da_tabela_e_limitado_as_pontas(): void
    {
        $this->assertSame(5, NormasCardio::percentilVo2max(10.0, 'M', 38)['percentil']);
        $this->assertSame(95, NormasCardio::percentilVo2max(99.0, 'M', 38)['percentil']);

        // Idades fora de 20-79 usam a faixa mais próxima em vez de quebrar.
        $this->assertNotNull(NormasCardio::percentilVo2max(45.0, 'M', 17));
        $this->assertNotNull(NormasCardio::percentilVo2max(20.0, 'M', 92));
    }

    public function test_percentil_sem_dado_devolve_null(): void
    {
        $this->assertNull(NormasCardio::percentilVo2max(null, 'M', 38));
        $this->assertNull(NormasCardio::percentilVo2max(45.0, 'M', null));
        $this->assertNull(NormasCardio::percentilVo2max(0.0, 'M', 38));
    }

    public function test_tabela_feminina_e_diferente_da_masculina(): void
    {
        $homem = NormasCardio::percentilVo2max(35.0, 'M', 35)['percentil'];
        $mulher = NormasCardio::percentilVo2max(35.0, 'F', 35)['percentil'];

        // O mesmo VO2max coloca uma mulher num percentil mais alto.
        $this->assertGreaterThan($homem, $mulher);
    }

    public function test_age_grade_do_recorde_mundial_da_cem_por_cento(): void
    {
        // 10 km masculino em 26:24 é o padrão da tabela WMA 2025 para 30 anos.
        $masculino = NormasCardio::ageGrade(10.0, 1584, 'M', 30);
        $this->assertSame(100.0, $masculino['percentual']);
        $this->assertSame(10, $masculino['distancia_referencia']);

        // 5 km feminino aos 50 anos: padrão de 971 s.
        $feminino = NormasCardio::ageGrade(5.0, 971, 'F', 50);
        $this->assertSame(100.0, $feminino['percentual']);
    }

    public function test_age_grade_melhora_com_a_idade_para_o_mesmo_tempo(): void
    {
        $jovem = NormasCardio::ageGrade(5.0, 1500, 'M', 30)['percentual'];
        $veterano = NormasCardio::ageGrade(5.0, 1500, 'M', 60)['percentual'];

        $this->assertGreaterThan($jovem, $veterano);
    }

    public function test_age_grade_escolhe_a_distancia_de_referencia(): void
    {
        $this->assertSame(5, NormasCardio::ageGrade(5.6, 2374, 'M', 38)['distancia_referencia']);
        $this->assertSame(10, NormasCardio::ageGrade(9.0, 3600, 'M', 38)['distancia_referencia']);

        // Distância curta demais não tem referência honesta.
        $this->assertNull(NormasCardio::ageGrade(1.0, 300, 'M', 38));
        $this->assertNull(NormasCardio::ageGrade(5.0, 1500, 'M', null));
    }

    public function test_riegel_faz_ida_e_volta(): void
    {
        $dezK = NormasCardio::riegel(1500, 5, 10);

        $this->assertGreaterThan(3000, $dezK); // mais que o dobro do tempo
        $this->assertSame(1500, NormasCardio::riegel($dezK, 10, 5));
    }

    public function test_vdot_e_o_tempo_correspondente_sao_inversos(): void
    {
        $tempo = NormasCardio::tempoParaVo2max(49.0, 5.0);

        $this->assertNotNull($tempo);
        $this->assertEqualsWithDelta(49.0, NormasCardio::vo2maxEstimado(5.0, $tempo), 0.2);
    }

    public function test_correr_mais_rapido_aumenta_o_vdot(): void
    {
        $lento = NormasCardio::vo2maxEstimado(5.0, 1800);
        $rapido = NormasCardio::vo2maxEstimado(5.0, 1200);

        $this->assertGreaterThan($lento, $rapido);
        $this->assertNull(NormasCardio::vo2maxEstimado(null, 1200));
        $this->assertNull(NormasCardio::vo2maxEstimado(5.0, 0));
    }

    public function test_idade_fitness_cai_quando_o_vo2max_sobe(): void
    {
        $this->assertLessThan(
            NormasCardio::idadeFitness(35.0, 'M'),
            NormasCardio::idadeFitness(49.0, 'M'),
        );

        $this->assertNull(NormasCardio::idadeFitness(null, 'M'));
    }

    public function test_projecao_por_peso_estima_ganho_de_ritmo(): void
    {
        $projecao = NormasCardio::projecaoPorPeso(49.0, 91.0, 85.0);

        $this->assertGreaterThan(49.0, $projecao['vo2max_projetado']);
        $this->assertGreaterThan(0, $projecao['ganho_seg_km']);
        $this->assertLessThan(
            $projecao['pace_atual_seg_km'],
            $projecao['pace_projetado_seg_km'],
        );

        // Diferença irrelevante de peso não vira promessa de ganho.
        $this->assertNull(NormasCardio::projecaoPorPeso(49.0, 91.0, 91.2));
        $this->assertNull(NormasCardio::projecaoPorPeso(49.0, 91.0, null));
    }

    public function test_fc_maxima_estimada_segue_tanaka(): void
    {
        // 208 - 0,7 x 38 = 181,4
        $this->assertSame(181, NormasCardio::fcMaximaEstimada(38));
        $this->assertSame(173, NormasCardio::fcMaximaEstimada(50));
        $this->assertNull(NormasCardio::fcMaximaEstimada(null));
        $this->assertNull(NormasCardio::fcMaximaEstimada(5));
    }
}
