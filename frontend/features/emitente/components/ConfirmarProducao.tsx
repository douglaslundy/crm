'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import type { Emitente, ModeloDocumento } from '../types';
import { Banner } from '@/components/ui/banner';

const ROTULO_DO_MODELO: Record<ModeloDocumento, string> = { NFE: 'NF-e', NFCE: 'NFC-e', DPS: 'NFS-e' };

/** Faixa de ambiente inconfundível + a ida para produção, que só abre depois de uma NF-e de teste autorizada. */
export function ConfirmarProducao({ emitente }: { emitente: Emitente }) {
  const queryClient = useQueryClient();
  const [entendo, setEntendo] = useState(false);
  const [erro, setErro] = useState<string | null>(null);
  const [numeros, setNumeros] = useState<Partial<Record<ModeloDocumento, string>>>({});
  const envioUnico = useEnvioUnico();
  const confirmar = useMutation({
    mutationFn: (n: Partial<Record<ModeloDocumento, number>>) => emitenteApi.confirmarProducao(n),
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
          noValidate
          className="space-y-3"
          onSubmit={envioUnico(async () => {
            setErro(null);
            const iniciais: Partial<Record<ModeloDocumento, number>> = {};
            for (const s of emitente.series) {
              const n = Number(numeros[s.modelo] ?? '1');
              if (!Number.isInteger(n) || n < 1) {
                setErro(`O primeiro número em produção (${ROTULO_DO_MODELO[s.modelo]}) deve ser um inteiro de pelo menos 1.`);
                return;
              }
              iniciais[s.modelo] = n;
            }
            await confirmar.mutateAsync(iniciais).catch(() => undefined);
          })}
        >
          <p className="text-sm text-muted-foreground">
            A numeração de produção é independente da de homologação. Informe o próximo número livre de cada série na SEFAZ.
          </p>
          {emitente.series.map((s) => (
            <div key={s.modelo} className="space-y-1">
              <label htmlFor={`inicial_${s.modelo}`} className="text-sm">{`Primeiro número em produção (${ROTULO_DO_MODELO[s.modelo]})`}</label>
              <Input
                id={`inicial_${s.modelo}`}
                type="number"
                min={1}
                value={numeros[s.modelo] ?? '1'}
                onChange={(e) => setNumeros((n) => ({ ...n, [s.modelo]: e.target.value }))}
              />
            </div>
          ))}
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
