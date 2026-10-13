'use client';

import { StatCard } from '@/components/ui/stat-card';
import { formatarCentavos } from '@/lib/formatos';
import { useResumoDoCrm } from '../hooks/useAtividades';

/** Bloco "CRM" do painel: atividades, parados, previsão e meta do mês. Some se a API falhar. */
export function BlocoCrmNoPainel() {
  const { data } = useResumoDoCrm();
  if (!data) return null;

  const meta = data.meta_do_mes;

  return (
    <section aria-label="CRM" className="space-y-2">
      <h2 className="font-display text-lg font-bold">CRM</h2>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard titulo="Hoje" valor={data.hoje} legenda="atividades para hoje" href="/crm/agenda" />
        <StatCard
          titulo="Atrasadas"
          valor={data.atrasadas}
          legenda="atividades em atraso"
          tom={data.atrasadas > 0 ? 'perigo' : 'primario'}
          href="/crm/agenda"
        />
        <StatCard titulo="Parados" valor={data.parados} legenda="negócios sem movimento" href="/crm/negocios" />
        <StatCard
          titulo="Previsão do mês"
          valor={formatarCentavos(data.previsao_do_mes.ponderada_centavos)}
          legenda={`${data.previsao_do_mes.quantidade} negócios com fechamento previsto`}
          href="/crm/relatorios"
        />
        {meta ? (
          <StatCard
            titulo="Meta do mês"
            valor={`${meta.percentual}%`}
            legenda={
              meta.unidade === 'VALOR'
                ? `${formatarCentavos(meta.realizado)} de ${formatarCentavos(meta.alvo)}`
                : `${meta.realizado} de ${meta.alvo} negócios`
            }
            tom={meta.percentual >= 100 ? 'sucesso' : 'primario'}
            href="/crm/relatorios"
          />
        ) : null}
      </div>
    </section>
  );
}
