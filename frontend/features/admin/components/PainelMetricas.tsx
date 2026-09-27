'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent } from '@/components/ui/card';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';
import { SITUACOES } from '../types';

function Metrica({ titulo, valor }: { titulo: string; valor: string }) {
  return (
    <Card role="group" aria-label={titulo}>
      <CardContent className="space-y-1 p-4">
        <p className="text-sm text-muted-foreground">{titulo}</p>
        <p className="text-2xl font-semibold">{valor}</p>
      </CardContent>
    </Card>
  );
}

export function PainelMetricas() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['admin', 'metricas'],
    queryFn: async () => (await adminApi.metricas()).data,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar as métricas.</p>;

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Metrica titulo="MRR estimado" valor={formatarCentavos(data.mrr_centavos)} />
      <Metrica titulo="Novas em 30 dias" valor={String(data.novas_30_dias)} />
      {SITUACOES.map((s) => <Metrica key={s} titulo={ROTULO_SITUACAO[s]} valor={String(data.por_situacao[s])} />)}
    </div>
  );
}
