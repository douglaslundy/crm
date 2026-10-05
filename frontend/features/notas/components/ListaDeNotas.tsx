'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { DataTable } from '@/components/ui/data-table';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Label } from '@/components/ui/label';
import { formatarCentavos } from '@/lib/formatos';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { ROTULO_STATUS, type StatusNota } from '../types';
import { StatusDaNota } from './StatusDaNota';
import { Banner } from '@/components/ui/banner';

export function ListaDeNotas() {
  const [status, setStatus] = useState<StatusNota | ''>('');
  const { data, isPending, isError } = useQuery({
    queryKey: [...QUERY_KEY_NOTAS, status],
    queryFn: async () => (await notasApi.listar(status === '' ? undefined : status)).data,
  });

  return (
    <div className="space-y-3">
      <div className="max-w-xs space-y-2">
        <Label htmlFor="filtro_status">Status</Label>
        <SelectNativo id="filtro_status" value={status} onChange={(e) => setStatus(e.target.value as StatusNota | '')}>
          <option value="">Todos</option>
          {(Object.keys(ROTULO_STATUS) as StatusNota[]).map((s) => (
            <option key={s} value={s}>{ROTULO_STATUS[s]}</option>
          ))}
        </SelectNativo>
      </div>

      {isError ? <Banner tom="perigo">Não foi possível carregar as notas.</Banner> : null}
      {!isError ? (
        <DataTable
          rotulo="Notas fiscais"
          carregando={isPending}
          vazio="Nenhuma nota encontrada."
          linhas={data ?? []}
          chaveDaLinha={(n) => n.id}
          colunas={[
            { chave: 'cliente', titulo: 'Cliente', render: (n) => <span className="font-medium">{n.cliente?.nome ?? 'Sem cliente'}</span> },
            { chave: 'numero', titulo: 'Número', numerica: true, render: (n) => (n.numero !== null ? `Nº ${n.numero} · série ${n.serie}` : 'Sem número') },
            { chave: 'total', titulo: 'Total', numerica: true, render: (n) => formatarCentavos(n.total_centavos) },
            { chave: 'status', titulo: 'Status', render: (n) => <StatusDaNota status={n.status} ambiente={n.ambiente} /> },
            { chave: 'abrir', titulo: '', render: (n) => <Link href={`/notas/${n.id}`} aria-label={`Abrir nota ${n.numero ?? 'sem número'}`} className="text-sm text-primary-text underline">Abrir</Link> },
          ]}
        />
      ) : null}
    </div>
  );
}
