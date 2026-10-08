'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { QUERY_KEY_ASSINATURA } from '@/features/assinatura/hooks/useAssinatura';
import { RECURSOS, ROTULO_RECURSO, type Plano, type Recurso } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { cobrancaApi, QUERY_KEY_FATURAS } from '../api';
import type { AssinaturaDeCobranca, Cobranca } from '../types';
import { PainelDePagamento } from './PainelDePagamento';

const INTERVALO_MS = 3000;

interface Excesso {
  recurso: Recurso;
  uso: number;
  limite: number;
}

/** Lê com segurança a lista `excessos` que o servidor devolve quando o plano novo não comporta o uso atual. */
function excessosDoErro(e: unknown): Excesso[] {
  if (!(e instanceof ApiError) || !Array.isArray(e.corpo.excessos)) return [];
  const lista: unknown[] = e.corpo.excessos;
  return lista.flatMap((item) => {
    if (typeof item !== 'object' || item === null) return [];
    const { recurso, uso, limite } = item as Record<string, unknown>;
    const conhecido = RECURSOS.find((r) => r === recurso);
    return conhecido !== undefined && typeof uso === 'number' && typeof limite === 'number' ? [{ recurso: conhecido, uso, limite }] : [];
  });
}

function precoDoCiclo(plano: Plano, ciclo: AssinaturaDeCobranca['ciclo']): number | null {
  return ciclo === 'ANUAL' ? plano.preco_anual_centavos : plano.preco_mensal_centavos;
}

/** Troca de plano de quem tem assinatura ativa e em dia: upgrade pago na hora, downgrade no fim do período. */
export function TrocaDePlano({ planos, assinatura }: { planos: Plano[]; assinatura: AssinaturaDeCobranca }) {
  const queryClient = useQueryClient();
  const [agendada, setAgendada] = useState<AssinaturaDeCobranca | null>(null);
  const [upgrade, setUpgrade] = useState<Cobranca | null>(null);
  const [erro, setErro] = useState<unknown>(null);
  const atual = agendada ?? assinatura;

  const faturas = useQuery({
    queryKey: QUERY_KEY_FATURAS,
    queryFn: async () => (await cobrancaApi.faturas()).data,
    enabled: upgrade !== null,
    refetchInterval: (query) => (query.state.data?.find((f) => f.id === upgrade?.id)?.status === 'PENDENTE' ? INTERVALO_MS : false),
  });
  const viva = faturas.data?.find((f) => f.id === upgrade?.id) ?? upgrade;

  // Upgrade pago pelo PIX: o plano da empresa mudou, então a assinatura é relida.
  useEffect(() => {
    if (viva?.status === 'PAGA') void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
  }, [viva?.status, queryClient]);

  const trocar = useMutation({
    mutationFn: (planoId: string) => cobrancaApi.trocarPlano(planoId),
    onSuccess: ({ data }) => {
      setErro(null);
      if (data.tipo === 'UPGRADE') {
        setUpgrade(data.cobranca);
        void queryClient.invalidateQueries({ queryKey: QUERY_KEY_FATURAS });
      } else {
        setAgendada(data.assinatura);
        void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
      }
    },
    onError: setErro,
  });
  const cancelar = useMutation({
    mutationFn: () => cobrancaApi.cancelarTroca(),
    onSuccess: ({ data }) => {
      setErro(null);
      setAgendada(data);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_ASSINATURA });
    },
    onError: setErro,
  });

  const proximo = atual.plano_proximo_id === null ? null : planos.find((p) => p.id === atual.plano_proximo_id);
  const excessos = excessosDoErro(erro);
  const mensagem = erro instanceof ApiError ? erro.primeiraMensagem() : erro === null ? null : 'Não foi possível trocar de plano. Tente novamente.';

  return (
    <Card>
      <CardHeader><CardTitle>Trocar de plano</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        {mensagem !== null ? (
          <Banner tom="perigo">
            {mensagem}
            {excessos.length > 0 ? (
              <ul className="mt-1 list-disc pl-5 font-normal">
                {excessos.map((x) => <li key={x.recurso}>{ROTULO_RECURSO[x.recurso]}: em uso {x.uso}, limite {x.limite}</li>)}
              </ul>
            ) : null}
          </Banner>
        ) : null}

        {viva !== null ? (
          <div className="space-y-2">
            <p className="font-medium">Troca de plano: diferença de {formatarCentavos(viva.valor_centavos)} a pagar agora</p>
            <PainelDePagamento cobranca={viva} />
          </div>
        ) : atual.plano_proximo_id !== null ? (
          <div className="space-y-2">
            <Banner tom="info">
              A troca para {proximo?.nome ?? 'o novo plano'} vale em {atual.plano_proximo_em ? formatarData(atual.plano_proximo_em) : 'breve'}. Até lá, o plano atual continua.
            </Banner>
            <Button type="button" variant="outline" disabled={cancelar.isPending} onClick={() => cancelar.mutate()}>Cancelar troca</Button>
          </div>
        ) : (
          <ul className="divide-y">
            {planos.filter((p) => p.id !== atual.plano_id && precoDoCiclo(p, atual.ciclo) !== null).map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>{p.nome} · {formatarCentavos(precoDoCiclo(p, atual.ciclo) ?? 0)}/{atual.ciclo === 'ANUAL' ? 'ano' : 'mês'}</span>
                <Button type="button" variant="outline" aria-label={`Trocar para ${p.nome}`} disabled={trocar.isPending} onClick={() => trocar.mutate(p.id)}>Trocar para este plano</Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
