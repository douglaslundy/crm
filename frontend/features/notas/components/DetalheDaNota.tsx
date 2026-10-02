'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { formatarCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { notasApi, QUERY_KEY_NOTAS, urlDoXml } from '../api';
import { ROTULO_PAGAMENTO, ROTULO_STATUS } from '../types';
import { NotaForm } from './NotaForm';
import { StatusDaNota } from './StatusDaNota';

const PAPEIS_QUE_EMITEM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];
const PAPEIS_QUE_RASCUNHAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];
const INTERVALO_DO_POLLING_MS = 2000;

export function DetalheDaNota({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const { data: usuario } = useUsuario();
  const [editando, setEditando] = useState(false);
  const [erroEmissao, setErroEmissao] = useState<string | null>(null);
  const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);
  const envioUnico = useEnvioUnico();

  const { data: nota, isPending, isError } = useQuery({
    queryKey: [...QUERY_KEY_NOTAS, id],
    queryFn: async () => (await notasApi.buscar(id)).data,
  });

  // Polling leve só enquanto a SEFAZ não respondeu; qualquer outro status encerra.
  const status = useQuery({
    queryKey: [...QUERY_KEY_NOTAS, id, 'status'],
    queryFn: async () => (await notasApi.status(id)).data,
    enabled: nota?.status === 'PROCESSANDO',
    refetchInterval: INTERVALO_DO_POLLING_MS,
  });
  const statusDoPolling = status.data?.status;
  useEffect(() => {
    if (statusDoPolling !== undefined && statusDoPolling !== 'PROCESSANDO') {
      void queryClient.invalidateQueries({ queryKey: [...QUERY_KEY_NOTAS, id] });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
    }
  }, [statusDoPolling, id, queryClient]);

  const emitir = useMutation({
    mutationFn: () => notasApi.emitir(id),
    onSuccess: () => {
      toast.info('Emissão iniciada.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
    },
    onError: (erro) => {
      setErroEmissao(erro instanceof ApiError ? erro.message : 'Não foi possível iniciar a emissão. Tente novamente.');
    },
  });

  const excluir = useMutation({
    mutationFn: () => notasApi.excluir(id),
    onSuccess: () => {
      toast.success('Rascunho excluído.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      router.push('/notas');
    },
    onError: (erro) => {
      toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível excluir.');
      setConfirmandoExclusao(false);
    },
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError || !nota) return <p role="alert">Não foi possível carregar a nota.</p>;

  const podeEmitir = Boolean(usuario && PAPEIS_QUE_EMITEM.includes(usuario.papel));
  const podeRascunhar = Boolean(usuario && PAPEIS_QUE_RASCUNHAM.includes(usuario.papel));
  const editavel = nota.status === 'RASCUNHO' || nota.status === 'REJEITADA' || nota.status === 'ERRO';
  const corrigivel = nota.status === 'REJEITADA' || nota.status === 'ERRO';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">{nota.numero !== null ? `NF-e nº ${nota.numero} · série ${nota.serie}` : 'NF-e (rascunho)'}</h1>
          <p className="text-sm text-muted-foreground">{nota.cliente?.nome ?? 'Sem cliente'} · {formatarCentavos(nota.total_centavos)}</p>
        </div>
        <StatusDaNota status={nota.status} ambiente={nota.ambiente} />
      </div>

      {nota.status === 'REJEITADA' ? (
        <p role="alert" className="rounded-md bg-danger/10 p-3 text-sm text-danger">cStat {nota.cstat}: {nota.motivo}</p>
      ) : null}
      {nota.status === 'ERRO' ? (
        <p role="alert" className="rounded-md bg-danger/10 p-3 text-sm text-danger">{nota.mensagem_erro}</p>
      ) : null}
      {erroEmissao ? <p role="alert" className="rounded-md bg-danger/10 p-3 text-sm text-danger">{erroEmissao}</p> : null}

      {nota.status === 'AUTORIZADA' ? (
        <Card>
          <CardHeader><CardTitle>Autorização</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>Chave de acesso</p>
            <p className="break-all font-mono">{nota.chave}</p>
            <p>Protocolo: <span className="font-mono">{nota.protocolo}</span></p>
            <a href={urlDoXml(nota.id)} className={buttonVariants({ variant: 'outline' })}>Baixar XML</a>
          </CardContent>
        </Card>
      ) : null}

      {nota.status === 'PROCESSANDO' ? (
        <p className="text-sm text-muted-foreground">Aguardando resposta da SEFAZ. Esta página atualiza sozinha.</p>
      ) : null}

      {editando && editavel ? (
        <Card>
          <CardHeader><CardTitle>Editar nota</CardTitle></CardHeader>
          <CardContent>
            <NotaForm nota={nota} onSalvo={() => setEditando(false)} />
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardHeader><CardTitle>Itens e pagamentos</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">
            <ul className="divide-y">
              {(nota.itens ?? []).map((i) => (
                <li key={i.id} className="flex justify-between gap-2 py-2">
                  <span>{i.descricao ?? i.sku ?? 'Item'} · {i.quantidade}</span>
                  <span>{formatarCentavos(i.total_centavos)}</span>
                </li>
              ))}
            </ul>
            <ul>
              {(nota.pagamentos ?? []).map((p, indice) => (
                <li key={indice} className="flex justify-between gap-2">
                  <span>{ROTULO_PAGAMENTO[p.tpag]}{p.xpag ? ` (${p.xpag})` : ''}</span>
                  <span>{formatarCentavos(p.valor_centavos)}</span>
                </li>
              ))}
            </ul>
            <p className="font-medium">Total: {formatarCentavos(nota.total_centavos)}</p>
          </CardContent>
        </Card>
      )}

      {editavel && !editando ? (
        <div className="flex flex-wrap items-center gap-2">
          {podeEmitir ? (
            <form
              onSubmit={envioUnico(async () => {
                setErroEmissao(null);
                await emitir.mutateAsync().catch(() => undefined);
              })}
            >
              <Button type="submit" disabled={emitir.isPending}>Emitir NF-e</Button>
            </form>
          ) : (
            <p className="text-sm text-muted-foreground">Você pode editar, mas só um usuário fiscal pode emitir.</p>
          )}
          {podeRascunhar ? (
            <Button type="button" variant="outline" onClick={() => setEditando(true)}>
              {corrigivel ? 'Corrigir e emitir de novo' : 'Editar'}
            </Button>
          ) : null}
          {nota.status === 'RASCUNHO' && podeRascunhar ? (
            confirmandoExclusao ? (
              <Button type="button" variant="destructive" disabled={excluir.isPending} onClick={() => excluir.mutate()}>Confirmar exclusão</Button>
            ) : (
              <Button type="button" variant="outline" onClick={() => setConfirmandoExclusao(true)}>Excluir rascunho</Button>
            )
          ) : null}
        </div>
      ) : null}

      <Card>
        <CardHeader><CardTitle>Histórico</CardTitle></CardHeader>
        <CardContent>
          <ul className="space-y-2 text-sm">
            {(nota.eventos ?? []).map((e, indice) => (
              <li key={indice}>
                <span className="font-medium">{ROTULO_STATUS[e.status_para]}</span>
                {e.detalhe ? <span> · {e.detalhe}</span> : null}
                <span className="block text-xs text-muted-foreground">{new Date(e.em).toLocaleString('pt-BR')}</span>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}
