'use client';

import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { mascararCnpj } from '@/lib/cnpj';
import { formatarData } from '@/lib/formatos';
import { adminApi } from '../api';
import { SITUACOES, type FiltrosEmpresas } from '../types';
import { BadgeSituacao } from './BadgeSituacao';

export function ListaDeEmpresas() {
  const [filtros, setFiltros] = useState<FiltrosEmpresas>({ situacao: '', plano_id: '', busca: '' });
  const [busca, setBusca] = useState('');
  const planos = useQuery({ queryKey: ['admin', 'planos'], queryFn: async () => (await adminApi.planos()).data });
  const consulta = useInfiniteQuery({
    queryKey: ['admin', 'empresas', filtros],
    queryFn: ({ pageParam }) => adminApi.empresas(filtros, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (pagina) => pagina.meta.next_cursor ?? undefined,
  });
  const empresas = consulta.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Empresas</h1>
      <form
        role="search"
        className="grid gap-2 sm:grid-cols-[1fr_12rem_12rem_auto]"
        onSubmit={(e) => {
          e.preventDefault();
          setFiltros((f) => ({ ...f, busca: busca.trim() }));
        }}
      >
        <Input aria-label="Buscar por CNPJ ou nome" placeholder="CNPJ ou nome" value={busca} onChange={(e) => setBusca(e.target.value)} />
        <SelectNativo
          aria-label="Situação"
          value={filtros.situacao}
          onChange={(e) => setFiltros((f) => ({ ...f, situacao: e.target.value as FiltrosEmpresas['situacao'] }))}
        >
          <option value="">Todas as situações</option>
          {SITUACOES.map((s) => <option key={s} value={s}>{ROTULO_SITUACAO[s]}</option>)}
        </SelectNativo>
        <SelectNativo aria-label="Plano" value={filtros.plano_id} onChange={(e) => setFiltros((f) => ({ ...f, plano_id: e.target.value }))}>
          <option value="">Todos os planos</option>
          {planos.data?.map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
        </SelectNativo>
        <Button type="submit">Buscar</Button>
      </form>

      {consulta.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {consulta.isError ? <p role="alert">Não foi possível carregar as empresas.</p> : null}
      {consulta.isSuccess && empresas.length === 0 ? <p className="text-muted-foreground">Nenhuma empresa encontrada.</p> : null}

      {empresas.length > 0 ? (
        <>
          <table className="hidden w-full text-sm md:table">
            <thead className="text-left text-muted-foreground">
              <tr><th className="p-2">Empresa</th><th className="p-2">CNPJ</th><th className="p-2">Plano</th><th className="p-2">Situação</th><th className="p-2">Criada em</th><th className="p-2"><span className="sr-only">Ações</span></th></tr>
            </thead>
            <tbody className="divide-y">
              {empresas.map((e) => (
                <tr key={e.id}>
                  <td className="p-2"><p className="font-medium">{e.razao_social}</p>{e.nome_fantasia ? <p className="text-muted-foreground">{e.nome_fantasia}</p> : null}</td>
                  <td className="p-2">{mascararCnpj(e.cnpj)}</td>
                  <td className="p-2">{e.plano?.nome ?? '—'}</td>
                  <td className="p-2"><BadgeSituacao situacao={e.situacao} /></td>
                  <td className="p-2">{formatarData(e.criada_em)}</td>
                  <td className="p-2 text-right"><Link href={`/admin/empresas/${e.id}`} className={buttonVariants({ variant: 'outline', size: 'sm' })}>Ver</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
          <ul className="space-y-2 md:hidden">
            {empresas.map((e) => (
              <li key={e.id} className="space-y-1 rounded-lg border p-3">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-medium">{e.razao_social}</p>
                  <BadgeSituacao situacao={e.situacao} />
                </div>
                <p className="text-sm text-muted-foreground">{mascararCnpj(e.cnpj)} · {e.plano?.nome ?? 'sem plano'}</p>
                <Link href={`/admin/empresas/${e.id}`} className="text-sm underline">Ver detalhes</Link>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {consulta.hasNextPage ? (
        <Button variant="outline" onClick={() => void consulta.fetchNextPage()} disabled={consulta.isFetchingNextPage}>
          {consulta.isFetchingNextPage ? 'Carregando...' : 'Carregar mais'}
        </Button>
      ) : null}
    </div>
  );
}
