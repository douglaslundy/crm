'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { formatarData } from '@/lib/formatos';
import { queryKeyEntregas, webhooksApi } from '../api';
import { EVENTOS_DE_NOTA, ROTULO_ESTADO, type Entrega } from '../types';

function rotuloDoEvento(evento: string): string {
  if (evento === 'webhook.teste') return 'Teste';
  return EVENTOS_DE_NOTA.find((e) => e.valor === evento)?.rotulo ?? evento;
}

/** '2026-10-08T11:00:00-03:00' → '08/10/2026 11:00' (hora do próprio texto, sem converter o fuso). */
function dataEHora(iso: string): string {
  return `${formatarData(iso)} ${iso.slice(11, 16)}`;
}

export function EntregasDoWebhook({ webhookId }: { webhookId: string }) {
  const queryClient = useQueryClient();
  const [erro, setErro] = useState<string | null>(null);
  const [detalhes, setDetalhes] = useState<ReadonlySet<string>>(new Set());

  const entregas = useQuery({ queryKey: queryKeyEntregas(webhookId), queryFn: async () => (await webhooksApi.entregas(webhookId)).data });

  const reenviar = useMutation({
    mutationFn: (entregaId: string) => webhooksApi.reenviar(entregaId),
    onSuccess: () => {
      setErro(null);
      void queryClient.invalidateQueries({ queryKey: queryKeyEntregas(webhookId) });
    },
    onError: (e) => setErro(e instanceof ApiError ? e.primeiraMensagem() : 'Não foi possível reenviar. Tente novamente.'),
  });

  function alternarDetalhes(id: string) {
    setDetalhes((atual) => {
      const novo = new Set(atual);
      if (novo.has(id)) novo.delete(id);
      else novo.add(id);
      return novo;
    });
  }

  return (
    <div className="mt-3 rounded-lg border bg-muted/30 p-3">
      <h3 className="mb-2 text-sm font-semibold">Entregas recentes</h3>
      {erro !== null ? <Banner tom="perigo">{erro}</Banner> : null}
      {entregas.isPending ? <p className="text-sm text-muted-foreground">Carregando...</p> : null}
      {entregas.isError ? <Banner tom="perigo">Não foi possível carregar as entregas.</Banner> : null}
      {entregas.data ? (
        entregas.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">Nenhuma entrega ainda.</p>
        ) : (
          <ul className="divide-y">
            {entregas.data.map((entrega) => (
              <LinhaDaEntrega
                key={entrega.id}
                entrega={entrega}
                aberta={detalhes.has(entrega.id)}
                ocupado={reenviar.isPending}
                onDetalhes={() => alternarDetalhes(entrega.id)}
                onReenviar={() => reenviar.mutate(entrega.id)}
              />
            ))}
          </ul>
        )
      ) : null}
    </div>
  );
}

interface LinhaProps {
  entrega: Entrega;
  aberta: boolean;
  ocupado: boolean;
  onDetalhes: () => void;
  onReenviar: () => void;
}

function LinhaDaEntrega({ entrega, aberta, ocupado, onDetalhes, onReenviar }: LinhaProps) {
  return (
    <li className="py-2 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <p>
            <span className="font-medium">{rotuloDoEvento(entrega.evento)}</span>{' '}
            <span className="rounded bg-muted px-1.5 py-0.5 text-xs font-semibold">{ROTULO_ESTADO[entrega.estado]}</span>
          </p>
          <p className="text-xs text-muted-foreground">
            {entrega.tentativas} {entrega.tentativas === 1 ? 'tentativa' : 'tentativas'} · criada em {dataEHora(entrega.criada_em)}
            {entrega.proxima_tentativa_em !== null ? ` · Próxima tentativa: ${dataEHora(entrega.proxima_tentativa_em)}` : ''}
          </p>
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="outline" size="sm" aria-expanded={aberta} onClick={onDetalhes}>Ver tentativas</Button>
          {entrega.estado === 'PENDENTE' ? null : (
            <Button type="button" size="sm" aria-label={`Reenviar ${rotuloDoEvento(entrega.evento)}`} disabled={ocupado} onClick={onReenviar}>Reenviar</Button>
          )}
        </div>
      </div>
      {aberta ? (
        <ul className="mt-2 space-y-1 text-xs">
          {entrega.tentativas_log.length === 0 ? <li className="text-muted-foreground">Nenhuma tentativa registrada.</li> : null}
          {entrega.tentativas_log.map((t) => (
            <li key={t.numero} className="rounded bg-background px-2 py-1">
              <span className="font-semibold">#{t.numero}</span> · {dataEHora(t.em)} · {t.status_http !== null ? `HTTP ${t.status_http}` : 'Sem resposta'} · {t.duracao_ms} ms
              {t.erro !== null ? <span className="block text-danger">{t.erro}</span> : null}
              {t.resposta !== null ? <span className="block break-all text-muted-foreground">{t.resposta}</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}
