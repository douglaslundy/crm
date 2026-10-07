'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { QUERY_KEY_NOTAS } from '@/features/notas/api';
import { StatusDaNota } from '@/features/notas/components/StatusDaNota';
import { ROTULO_MODELO } from '@/features/notas/types';
import { ApiError } from '@/lib/api';
import { formatarCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_VENDAS, vendasApi } from '../api';
import type { NotaDaEmissao } from '../types';

const PAPEIS_QUE_EMITEM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];
const PAPEIS_QUE_RASCUNHAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];
const INTERVALO_DO_POLLING_MS = 3000;
const EMITIVEIS = ['RASCUNHO', 'REJEITADA', 'ERRO'];

export function DetalheDaVenda({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const { data: usuario } = useUsuario();
  const envioUnico = useEnvioUnico();
  const [bloqueios, setBloqueios] = useState<NotaDaEmissao[]>([]);
  const [erro, setErro] = useState<string | null>(null);
  const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);

  const { data: venda, isPending, isError } = useQuery({
    queryKey: [...QUERY_KEY_VENDAS, id],
    queryFn: async () => (await vendasApi.buscar(id)).data,
    // Atualiza sozinha enquanto alguma nota espera a resposta da SEFAZ ou da ADN.
    refetchInterval: (consulta) => (consulta.state.data?.notas?.some((n) => n.status === 'PROCESSANDO') ? INTERVALO_DO_POLLING_MS : false),
  });

  const emitir = useMutation({
    mutationFn: (apenasValidas: boolean) => vendasApi.emitir(id, apenasValidas),
    onSuccess: ({ data }) => {
      const pendentes = data.notas.filter((n) => n.bloqueio !== null);
      setBloqueios(pendentes);
      if (pendentes.length === 0) toast.info('Emissão iniciada nas duas notas.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_VENDAS });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
    },
    onError: (e) => {
      if (e instanceof ApiError && e.codigo === 'FISCAL_VENDA_NOTA_BLOQUEADA' && Array.isArray(e.corpo.notas)) {
        setBloqueios((e.corpo.notas as NotaDaEmissao[]).filter((n) => n.bloqueio !== null));
        return;
      }
      setErro(e instanceof ApiError ? e.message : 'Não foi possível iniciar a emissão. Tente novamente.');
    },
  });

  const excluir = useMutation({
    mutationFn: () => vendasApi.excluir(id),
    onSuccess: () => {
      toast.success('Venda excluída.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_VENDAS });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      router.push('/notas');
    },
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.message : 'Não foi possível excluir.');
      setConfirmandoExclusao(false);
    },
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError || !venda) return <Banner tom="perigo">Não foi possível carregar a venda.</Banner>;

  const notas = [...(venda.notas ?? [])].sort((a, b) => a.modelo.localeCompare(b.modelo));
  const podeEmitir = Boolean(usuario && PAPEIS_QUE_EMITEM.includes(usuario.papel));
  const podeRascunhar = Boolean(usuario && PAPEIS_QUE_RASCUNHAM.includes(usuario.papel));
  const todasEmRascunho = notas.length === 2 && notas.every((n) => n.status === 'RASCUNHO');
  const algumaEmitivel = notas.some((n) => EMITIVEIS.includes(n.status));
  const algumaValida = notas.some((n) => EMITIVEIS.includes(n.status) && !bloqueios.some((b) => b.nota_id === n.id));

  return (
    <div className="space-y-4">
      <div>
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Venda de produtos e serviços</h1>
        <p className="text-sm text-muted-foreground">
          {venda.cliente?.nome} · total {formatarCentavos(venda.total_centavos)}
          {venda.desconto_centavos > 0 ? ` (desconto de ${formatarCentavos(venda.desconto_centavos)})` : ''}
        </p>
      </div>

      {bloqueios.map((b) => (
        <Banner key={b.nota_id} tom="perigo">{ROTULO_MODELO[b.modelo]}: {b.bloqueio?.mensagem}</Banner>
      ))}
      {erro ? <Banner tom="perigo">{erro}</Banner> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        {notas.map((n) => (
          <Card key={n.id}>
            <CardHeader><CardTitle>{ROTULO_MODELO[n.modelo]}</CardTitle></CardHeader>
            <CardContent className="space-y-2 text-sm">
              <StatusDaNota status={n.status} />
              {n.numero !== null ? <p>Nº {n.numero} · série {n.serie}</p> : null}
              <p>Itens {formatarCentavos(n.subtotal_centavos)} · desconto {formatarCentavos(n.desconto_centavos)}</p>
              <p className="font-medium">Total {formatarCentavos(n.total_centavos)}</p>
              <Link href={`/notas/${n.id}`} aria-label={`Abrir ${ROTULO_MODELO[n.modelo]}`} className={buttonVariants({ variant: 'outline' })}>Abrir nota</Link>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {podeEmitir && algumaEmitivel ? (
          <form
            onSubmit={envioUnico(async () => {
              setErro(null);
              setBloqueios([]);
              await emitir.mutateAsync(false).catch(() => undefined);
            })}
          >
            <Button type="submit" disabled={emitir.isPending}>Emitir as duas</Button>
          </form>
        ) : null}
        {podeEmitir && bloqueios.length > 0 && algumaValida ? (
          <Button
            type="button"
            variant="secondary"
            disabled={emitir.isPending}
            onClick={() => {
              setErro(null);
              emitir.mutate(true);
            }}
          >
            Emitir só a válida
          </Button>
        ) : null}
        {podeRascunhar && todasEmRascunho ? (
          <Link href={`/vendas/${id}/editar`} className={buttonVariants({ variant: 'outline' })}>Editar venda</Link>
        ) : null}
        {podeRascunhar && todasEmRascunho ? (
          confirmandoExclusao ? (
            <Button type="button" variant="destructive" disabled={excluir.isPending} onClick={() => excluir.mutate()}>Confirmar exclusão</Button>
          ) : (
            <Button type="button" variant="outline" onClick={() => setConfirmandoExclusao(true)}>Excluir venda</Button>
          )
        ) : null}
        {!podeEmitir ? <p className="text-sm text-muted-foreground">Só um usuário fiscal pode emitir.</p> : null}
      </div>
    </div>
  );
}
