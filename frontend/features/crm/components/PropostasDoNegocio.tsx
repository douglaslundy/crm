'use client';

import Link from 'next/link';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { urlDoPdfDaProposta } from '../api';
import {
  useCancelarProposta, useCriarProposta, useEnviarProposta, useGerarNotaDaProposta, usePropostasDoNegocio,
} from '../hooks/usePropostas';
import type { Negocio, Proposta, StatusDaProposta } from '../types';
import { BotoesDeContato } from './BotoesDeContato';

const ROTULO_STATUS: Record<StatusDaProposta, string> = {
  RASCUNHO: 'Rascunho', ENVIADA: 'Enviada', ACEITA: 'Aceita', RECUSADA: 'Recusada', CANCELADA: 'Cancelada', EXPIRADA: 'Expirada',
};

interface Props {
  negocio: Negocio;
  podeEscrever: boolean;
  /** O plano inclui NF-e ou NFS-e: sem isso o botão "Emitir nota" nem aparece. */
  temFiscal: boolean;
}

async function copiar(link: string) {
  try {
    await navigator.clipboard.writeText(link);
    toast.success('Link copiado.');
  } catch {
    toast.error('Não foi possível copiar. Selecione e copie o link manualmente.');
  }
}

function LinhaDaProposta({ p, negocio, podeEscrever, temFiscal }: { p: Proposta } & Props) {
  const enviar = useEnviarProposta();
  const cancelar = useCancelarProposta();
  const gerarNota = useGerarNotaDaProposta();
  const ocupado = enviar.isPending || cancelar.isPending || gerarNota.isPending;

  return (
    <li className="space-y-2 rounded-lg border border-border bg-card p-3 text-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p>
          <strong>Proposta #{p.numero}</strong>
          <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs font-medium">{ROTULO_STATUS[p.status]}</span>
        </p>
        <p><strong>{formatarCentavos(p.total_centavos)}</strong></p>
      </div>
      <p className="text-xs text-muted-foreground">
        {p.validade_em ? `Válida até ${formatarData(p.validade_em)}` : `Validade: ${p.validade_dias} dias após o envio`}
        {p.aceite_nome ? ` · Aceita por ${p.aceite_nome}` : ''}
        {p.motivo_recusa ? ` · Motivo da recusa: ${p.motivo_recusa}` : ''}
      </p>

      <div className="flex flex-wrap items-center gap-2">
        <a
          href={urlDoPdfDaProposta(p.id)}
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm underline"
        >
          Baixar PDF
        </a>

        {podeEscrever && p.status === 'RASCUNHO' ? (
          <Button size="sm" disabled={ocupado} onClick={() => enviar.mutate(p.id)}>Enviar</Button>
        ) : null}

        {podeEscrever && p.status === 'ENVIADA' && p.link ? (
          <>
            <Button size="sm" variant="outline" onClick={() => void copiar(p.link ?? '')}>Copiar link</Button>
            <BotoesDeContato clienteId={negocio.cliente?.id} negocioId={negocio.id} podeEscrever />
          </>
        ) : null}

        {podeEscrever && (p.status === 'RASCUNHO' || p.status === 'ENVIADA') ? (
          <Button size="sm" variant="outline" disabled={ocupado} onClick={() => cancelar.mutate(p.id)}>Cancelar</Button>
        ) : null}

        {podeEscrever && p.status === 'ACEITA' && temFiscal && p.notas_ids.length === 0 ? (
          <Button size="sm" disabled={ocupado} onClick={() => gerarNota.mutate(p.id)}>Emitir nota</Button>
        ) : null}

        {p.status === 'ACEITA'
          ? p.notas_ids.map((notaId, i) => (
              <Link key={notaId} href={`/notas/${notaId}`} className="text-sm underline">
                Abrir rascunho{p.notas_ids.length > 1 ? ` ${i + 1}` : ''}
              </Link>
            ))
          : null}
      </div>
    </li>
  );
}

export function PropostasDoNegocio(props: Props) {
  const { negocio, podeEscrever } = props;
  const propostas = usePropostasDoNegocio(negocio.id);
  const criar = useCriarProposta(negocio.id);

  if (propostas.isPending) return <p className="text-sm text-muted-foreground">Carregando...</p>;
  if (propostas.isError) return <p className="text-sm text-destructive">Não foi possível carregar as propostas.</p>;

  return (
    <div className="space-y-3">
      {podeEscrever && negocio.status === 'ABERTO' ? (
        <Button size="sm" variant="outline" disabled={criar.isPending} onClick={() => criar.mutate({})}>Nova proposta</Button>
      ) : null}

      {propostas.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nenhuma proposta para este negócio.</p>
      ) : (
        <ul className="space-y-2">
          {propostas.data.map((p) => <LinhaDaProposta key={p.id} p={p} {...props} />)}
        </ul>
      )}
    </div>
  );
}
