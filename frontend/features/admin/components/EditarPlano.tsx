'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { adminApi } from '../api';
import { PlanoForm } from './PlanoForm';

export function EditarPlano({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const chave = ['admin', 'planos', id];
  const [confirmando, setConfirmando] = useState(false);
  const { data: plano, isPending, isError } = useQuery({ queryKey: chave, queryFn: async () => (await adminApi.plano(id)).data });

  const desativar = useMutation({
    mutationFn: () => adminApi.desativarPlano(id),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(chave, data);
      void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'], exact: true });
      toast.success('Plano desativado.');
      setConfirmando(false);
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar o plano.</p>;

  const empresas = plano.empresas ?? 0;

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Editar plano</h1>
      {empresas > 0 ? (
        <p role="note" className="rounded-md bg-warning/10 p-3 text-sm text-warning">
          {empresas === 1 ? '1 empresa usa' : `${empresas} empresas usam`} este plano. As alterações valem para todas.
        </p>
      ) : null}
      <PlanoForm
        key={plano.id}
        inicial={plano}
        rotuloBotao="Salvar alterações"
        onSalvar={async (entrada) => {
          const { data } = await adminApi.atualizarPlano(id, entrada);
          queryClient.setQueryData(chave, data);
          void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'], exact: true });
          toast.success('Plano salvo.');
        }}
      />
      {plano.ativo ? (
        <div className="flex flex-wrap items-center gap-2 border-t pt-4">
          {confirmando ? (
            <>
              <span className="text-sm">O plano sai da página de planos. As empresas atuais continuam nele.</span>
              <Button variant="destructive" onClick={() => desativar.mutate()} disabled={desativar.isPending}>Confirmar desativação</Button>
              <Button variant="ghost" onClick={() => setConfirmando(false)}>Cancelar</Button>
            </>
          ) : (
            <Button variant="outline" onClick={() => setConfirmando(true)}>Desativar plano</Button>
          )}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">Plano desativado: não aparece para novos clientes.</p>
      )}
    </div>
  );
}
