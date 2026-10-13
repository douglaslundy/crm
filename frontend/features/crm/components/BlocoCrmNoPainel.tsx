'use client';

import { StatCard } from '@/components/ui/stat-card';
import { useResumoDoCrm } from '../hooks/useAtividades';

/** Bloco "CRM" do painel: atividades de hoje, atrasadas e negócios parados. Some se a API falhar. */
export function BlocoCrmNoPainel() {
  const { data } = useResumoDoCrm();
  if (!data) return null;

  return (
    <section aria-label="CRM" className="space-y-2">
      <h2 className="font-display text-lg font-bold">CRM</h2>
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard titulo="Hoje" valor={data.hoje} legenda="atividades para hoje" href="/crm/agenda" />
        <StatCard
          titulo="Atrasadas"
          valor={data.atrasadas}
          legenda="atividades em atraso"
          tom={data.atrasadas > 0 ? 'perigo' : 'primario'}
          href="/crm/agenda"
        />
        <StatCard titulo="Parados" valor={data.parados} legenda="negócios sem movimento" href="/crm/negocios" />
      </div>
    </section>
  );
}
