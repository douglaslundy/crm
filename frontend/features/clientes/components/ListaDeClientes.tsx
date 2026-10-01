'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { clientesApi, QUERY_KEY_CLIENTES } from '../api';
import { ClienteForm } from './ClienteForm';
import { ContatoForm } from './ContatoForm';

export function ListaDeClientes({ podeEscrever }: { podeEscrever: boolean }) {
  const queryClient = useQueryClient();
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const converter = useMutation({
    mutationFn: (id: string) => clientesApi.converterEmCliente(id),
    onSuccess: () => {
      toast.success('Lead convertido em cliente.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os clientes.</p>;

  return (
    <ul className="divide-y">
      {data.map((c) => (
        <li key={c.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{c.nome} <span className="text-xs text-muted-foreground">({c.estagio === 'LEAD' ? 'lead' : 'cliente'})</span></p>
              <p className="truncate text-sm text-muted-foreground">{c.tipo} · {c.cpf_cnpj ?? 'sem documento'}</p>
            </div>
            <div className="flex gap-2">
              {podeEscrever && c.estagio === 'LEAD' ? (
                <Button variant="outline" size="sm" aria-label={`Converter ${c.nome} em cliente`} disabled={converter.isPending} onClick={() => converter.mutate(c.id)}>
                  Converter em cliente
                </Button>
              ) : null}
              {podeEscrever ? (
                <Button variant="outline" size="sm" aria-label={`Editar ${c.nome}`} onClick={() => setEditando(editando === c.id ? null : c.id)}>Editar</Button>
              ) : null}
            </div>
          </div>
          {editando === c.id ? (
            <div className="mt-3 space-y-3 border-t pt-3">
              <ClienteForm cliente={c} onSalvar={() => setEditando(null)} />
              {c.tipo === 'PJ' ? (
                <div>
                  <h3 className="mb-2 text-sm font-semibold text-muted-foreground">Contatos</h3>
                  <ul className="mb-2 space-y-1 text-sm">
                    {c.contatos?.map((ct) => <li key={ct.id}>{ct.nome}{ct.cargo ? ` · ${ct.cargo}` : ''}</li>)}
                  </ul>
                  <ContatoForm clienteId={c.id} />
                </div>
              ) : null}
            </div>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
