'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { pendenciasFiscaisApi, QUERY_KEY_PENDENCIAS_FISCAIS } from '../api';

export function PendenciasFiscais({ podeRevisar }: { podeRevisar: boolean }) {
  const queryClient = useQueryClient();
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_PENDENCIAS_FISCAIS, queryFn: async () => (await pendenciasFiscaisApi.listar()).data });
  const marcarRevisado = useMutation({
    mutationFn: (produtoId: string) => pendenciasFiscaisApi.marcarRevisado(produtoId),
    onSuccess: () => {
      toast.success('Produto marcado como revisado.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_PENDENCIAS_FISCAIS });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar as pendências.</p>;

  const semPendencias = data.produtos.length === 0 && data.servicos.length === 0;
  if (semPendencias) return <p className="text-muted-foreground">Nenhuma pendência fiscal. Tudo revisado.</p>;

  return (
    <div className="space-y-4">
      {data.produtos.length > 0 ? (
        <section>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Produtos</h2>
          <ul className="divide-y">
            {data.produtos.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-2 py-2">
                <span>{p.nome} <span className="text-xs text-muted-foreground">({p.sku})</span></span>
                {podeRevisar && p.fiscal_fonte === 'PADRAO' ? (
                  <Button variant="outline" size="sm" aria-label={`Marcar ${p.nome} como revisado`} onClick={() => marcarRevisado.mutate(p.id)}>
                    Marcar revisado
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      {data.servicos.length > 0 ? (
        <section>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Serviços</h2>
          <ul className="divide-y">
            {data.servicos.map((s) => <li key={s.id} className="py-2">{s.nome}</li>)}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
