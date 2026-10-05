'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
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

      {isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {isError ? <Banner tom="perigo">Não foi possível carregar as notas.</Banner> : null}
      {data && data.length === 0 ? <p className="text-muted-foreground">Nenhuma nota encontrada.</p> : null}
      {data && data.length > 0 ? (
        <ul className="divide-y">
          {data.map((n) => (
            <li key={n.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
              <div className="min-w-0">
                <p className="font-medium">{n.cliente?.nome ?? 'Sem cliente'}</p>
                <p className="text-sm text-muted-foreground">
                  {n.numero !== null ? `Nº ${n.numero} · série ${n.serie}` : 'Sem número'} · {formatarCentavos(n.total_centavos)}
                </p>
              </div>
              <div className="flex items-center gap-3">
                <StatusDaNota status={n.status} ambiente={n.ambiente} />
                <Link href={`/notas/${n.id}`} aria-label={`Abrir nota ${n.numero ?? 'sem número'}`} className="text-sm underline">Abrir</Link>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
