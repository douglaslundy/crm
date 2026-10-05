'use client';

import { useQuery } from '@tanstack/react-query';
import { inutilizacoesApi, QUERY_KEY_INUTILIZACOES } from '../api';
import { ROTULO_MODELO, ROTULO_STATUS_INUTILIZACAO } from '../types';
import { Banner } from '@/components/ui/banner';

export function ListaDeInutilizacoes() {
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_INUTILIZACOES, queryFn: async () => (await inutilizacoesApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <Banner tom="perigo">Não foi possível carregar as inutilizações.</Banner>;
  if (data.length === 0) return <p className="text-muted-foreground">Nenhuma inutilização registrada.</p>;

  return (
    <ul className="divide-y">
      {data.map((i) => (
        <li key={i.id} className="space-y-1 py-3 text-sm">
          <p className="font-medium">
            {ROTULO_MODELO[i.modelo]} · série {i.serie} · números {i.numero_inicial} a {i.numero_final} · {i.ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}
          </p>
          <p>
            {ROTULO_STATUS_INUTILIZACAO[i.status]}
            {i.cstat ? ` · cStat ${i.cstat}` : ''}
            {i.motivo ? `: ${i.motivo}` : ''}
          </p>
          <p className="text-xs text-muted-foreground">{new Date(i.created_at).toLocaleString('pt-BR')}</p>
        </li>
      ))}
    </ul>
  );
}
