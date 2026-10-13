'use client';

import { Check, RotateCcw } from 'lucide-react';
import Link from 'next/link';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useConcluirAtividade, useReabrirAtividade } from '../hooks/useAtividades';
import type { Atividade, TipoDeAtividade } from '../types';

export const ROTULO_DO_TIPO: Record<TipoDeAtividade, string> = {
  LIGACAO: 'Ligação', REUNIAO: 'Reunião', EMAIL: 'E-mail', WHATSAPP: 'WhatsApp', TAREFA: 'Tarefa',
};

function quando(iso: string): string {
  return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export function ItemDeAtividade({ atividade, podeEscrever }: { atividade: Atividade; podeEscrever: boolean }) {
  const concluir = useConcluirAtividade();
  const reabrir = useReabrirAtividade();
  const concluida = atividade.concluida_em !== null;

  return (
    <li className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card p-3 text-sm">
      <div className="min-w-0 space-y-1">
        <p className={cn('font-semibold', concluida && 'text-muted-foreground line-through')}>
          <span className="mr-2 rounded bg-muted px-1.5 py-0.5 text-xs font-medium">{ROTULO_DO_TIPO[atividade.tipo]}</span>
          {atividade.titulo}
        </p>
        <p className={cn('text-xs', atividade.atrasada ? 'text-danger' : 'text-muted-foreground')}>
          {quando(atividade.agendada_para)}
          {atividade.atrasada ? ' · atrasada' : ''}
          {atividade.responsavel ? ` · ${atividade.responsavel.nome}` : ''}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {atividade.cliente ? (
            <Link href={`/crm/clientes/${atividade.cliente.id}`} className="underline">{atividade.cliente.nome}</Link>
          ) : null}
          {atividade.negocio ? (
            <>
              {' · '}
              <Link href={`/crm/negocios/${atividade.negocio.id}`} className="underline">{atividade.negocio.titulo}</Link>
            </>
          ) : null}
        </p>
      </div>
      {podeEscrever ? (
        concluida ? (
          <Button variant="outline" size="sm" disabled={reabrir.isPending} onClick={() => reabrir.mutate(atividade.id)}>
            <RotateCcw aria-hidden /> Reabrir
          </Button>
        ) : (
          <Button size="sm" disabled={concluir.isPending} onClick={() => concluir.mutate(atividade.id)}>
            <Check aria-hidden /> Concluir
          </Button>
        )
      ) : null}
    </li>
  );
}
