'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { produtosApi, QUERY_KEY_PRODUTOS } from '../api';
import { ProdutoForm } from './ProdutoForm';
import { Banner } from '@/components/ui/banner';
import { EstadoVazio } from '@/components/ui/estado-vazio';

export function ListaDeProdutos() {
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_PRODUTOS, queryFn: async () => (await produtosApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <Banner tom="perigo">Não foi possível carregar os produtos.</Banner>;

  if (data.length === 0) return <EstadoVazio titulo="Nenhum produto cadastrado">Cadastre o primeiro no formulário &quot;Novo produto&quot; ou importe um CSV.</EstadoVazio>;

  return (
    <ul className="divide-y">
      {data.map((p) => (
        <li key={p.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{p.nome}</p>
              <p className="truncate text-sm text-muted-foreground">
                {p.sku} · {p.unidade} {p.pendente_fiscal ? <span className="text-amber-600">· pendente fiscal</span> : null}
              </p>
            </div>
            <Button variant="outline" size="sm" aria-label={`Editar ${p.nome}`} onClick={() => setEditando(p.id)}>Editar</Button>
          </div>
          {editando === p.id ? <ProdutoForm produto={p} onSalvar={() => setEditando(null)} /> : null}
        </li>
      ))}
    </ul>
  );
}
