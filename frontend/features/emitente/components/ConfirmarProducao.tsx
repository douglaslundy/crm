'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import type { Emitente } from '../types';
import { Banner } from '@/components/ui/banner';

/** Faixa de ambiente inconfundível + a ida para produção, que só abre depois de uma NF-e de teste autorizada. */
export function ConfirmarProducao({ emitente }: { emitente: Emitente }) {
  const queryClient = useQueryClient();
  const [entendo, setEntendo] = useState(false);
  const [erro, setErro] = useState<string | null>(null);
  const envioUnico = useEnvioUnico();
  const confirmar = useMutation({
    mutationFn: () => emitenteApi.confirmarProducao(),
    onSuccess: () => {
      toast.success('Ambiente de produção ativado.');
      setEntendo(false);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
    },
    onError: (e) => setErro(e instanceof ApiError ? e.message : 'Não foi possível mudar o ambiente. Tente novamente.'),
  });

  const emProducao = emitente.ambiente_fiscal === 'PRODUCAO';

  return (
    <div className="space-y-3">
      <div role="status" className={emProducao ? 'rounded-md bg-danger/15 p-3 text-sm font-medium text-danger' : 'rounded-md bg-info/15 p-3 text-sm font-medium text-info'}>
        {emProducao ? 'Ambiente: PRODUÇÃO. As notas emitidas têm valor fiscal.' : 'Ambiente: HOMOLOGAÇÃO. As notas emitidas não têm valor fiscal.'}
      </div>

      {!emProducao && !emitente.emissao_de_teste_autorizada ? (
        <p className="text-sm text-muted-foreground">
          Emita ao menos uma NF-e de teste em homologação para liberar a produção.{' '}
          <Link href="/notas/nova" className="underline">Emitir NF-e de teste</Link>
        </p>
      ) : null}

      {!emProducao && emitente.emissao_de_teste_autorizada ? (
        <form
          className="space-y-3"
          onSubmit={envioUnico(async () => {
            setErro(null);
            await confirmar.mutateAsync().catch(() => undefined);
          })}
        >
          <div className="flex items-start gap-2">
            <input id="entendo_producao" type="checkbox" className="mt-1" checked={entendo} onChange={(e) => setEntendo(e.target.checked)} />
            <label htmlFor="entendo_producao" className="text-sm">Entendo que as notas emitidas em produção têm valor fiscal</label>
          </div>
          {erro ? <Banner tom="perigo">{erro}</Banner> : null}
          <Button type="submit" disabled={!entendo || confirmar.isPending}>Ir para produção</Button>
        </form>
      ) : null}
    </div>
  );
}
