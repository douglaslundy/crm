'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { crmApi, QUERY_KEY_CRM } from '../api';
import { useMotivosDePerda } from '../hooks/useMotivosDePerda';
import type { StatusDoNegocio } from '../types';
import { EditorDeItens } from './EditorDeItens';
import { PainelDePerda } from './PainelDePerda';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'VENDEDOR'];

const ROTULO_STATUS: Record<StatusDoNegocio, string> = { ABERTO: 'Aberto', GANHO: 'Ganho', PERDIDO: 'Perdido' };

export function DetalheDoNegocio({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));
  const [perdendo, setPerdendo] = useState(false);

  const negocio = useQuery({ queryKey: [...QUERY_KEY_CRM, 'negocio', id], queryFn: async () => (await crmApi.negocio(id)).data });
  const motivos = useMotivosDePerda();
  const ganhar = useMutation({
    mutationFn: () => crmApi.ganhar(id),
    onSuccess: () => {
      toast.success('Negócio ganho.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (negocio.isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (negocio.isError) return <Banner tom="perigo">Negócio não encontrado ou sem acesso.</Banner>;

  const n = negocio.data;
  const motivo = motivos.data?.find((m) => m.id === n.motivo_de_perda_id);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">{n.titulo}</h1>
        <Link href="/crm/negocios" className="text-sm underline">Voltar ao quadro</Link>
      </div>

      <Card>
        <CardHeader><CardTitle>Dados</CardTitle></CardHeader>
        <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
          <p><span className="text-muted-foreground">Situação: </span><strong>{ROTULO_STATUS[n.status]}</strong></p>
          <p><span className="text-muted-foreground">Valor: </span><strong>{formatarCentavos(n.valor_centavos)}</strong></p>
          <p><span className="text-muted-foreground">Cliente: </span>{n.cliente?.nome ?? '—'}{n.cliente?.estagio === 'LEAD' ? ' (lead)' : ''}</p>
          <p><span className="text-muted-foreground">Contato: </span>{n.cliente?.telefone ?? n.cliente?.email ?? '—'}</p>
          <p><span className="text-muted-foreground">Responsável: </span>{n.responsavel?.nome ?? '—'}</p>
          <p>
            <span className="text-muted-foreground">Previsão: </span>
            {n.previsao_fechamento ? formatarData(n.previsao_fechamento) : '—'}
          </p>
          {n.fechado_em ? <p><span className="text-muted-foreground">Fechado em: </span>{formatarData(n.fechado_em)}</p> : null}
          {n.status === 'PERDIDO' ? (
            <p className="sm:col-span-2">
              <span className="text-muted-foreground">Motivo da perda: </span>
              {motivo?.nome ?? '—'}
              {n.observacao_da_perda ? ` · ${n.observacao_da_perda}` : ''}
            </p>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Itens</CardTitle></CardHeader>
        <CardContent><EditorDeItens negocio={n} podeEditar={podeEscrever} /></CardContent>
      </Card>

      {n.status === 'ABERTO' && podeEscrever ? (
        <div className="space-y-3">
          <div className="flex gap-2">
            <Button disabled={ganhar.isPending} onClick={() => ganhar.mutate()}>Ganhar</Button>
            <Button variant="outline" onClick={() => setPerdendo((v) => !v)}>Perder</Button>
          </div>
          {perdendo ? <PainelDePerda negocioId={n.id} onConcluido={() => setPerdendo(false)} /> : null}
        </div>
      ) : null}
    </div>
  );
}
