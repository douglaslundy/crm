'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { QUERY_KEY_SERVICOS, servicosApi } from '../api';
import { ServicoForm } from './ServicoForm';

export function ListaDeServicos() {
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os serviços.</p>;

  return (
    <ul className="divide-y">
      {data.map((s) => (
        <li key={s.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{s.nome}</p>
              <p className="truncate text-sm text-muted-foreground">
                {s.pendente_fiscal ? <span className="text-amber-600">pendente fiscal</span> : 'dados fiscais completos'}
              </p>
            </div>
            <Button variant="outline" size="sm" aria-label={`Editar ${s.nome}`} onClick={() => setEditando(s.id)}>Editar</Button>
          </div>
          {editando === s.id ? <ServicoForm servico={s} onSalvar={() => setEditando(null)} /> : null}
        </li>
      ))}
    </ul>
  );
}
