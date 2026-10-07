'use client';

import { useQuery } from '@tanstack/react-query';
import { Banner } from '@/components/ui/banner';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { notasApi } from '@/features/notas/api';
import { vendasApi } from '../api';
import { VendaForm } from './VendaForm';

// Rascunho, rejeitada e erro: a nota que falhou por dado próprio precisa poder ser corrigida pela venda.
const EDITAVEIS = ['RASCUNHO', 'REJEITADA', 'ERRO'];

/** Abre a venda para edição: carrega o cabeçalho e as duas notas (de onde vêm itens e pagamentos). Só com nenhuma das duas já emitida. */
export function EditarVenda({ id }: { id: string }) {
  const dados = useQuery({
    queryKey: ['vendas', id, 'editar'],
    queryFn: async () => {
      const venda = (await vendasApi.buscar(id)).data;
      const [produtos, servicos] = await Promise.all(
        ['NFE', 'DPS'].map(async (modelo) => {
          const nota = venda.notas?.find((n) => n.modelo === modelo);
          if (!nota) throw new Error('A venda não tem as duas notas.');
          return (await notasApi.buscar(nota.id)).data;
        }),
      );
      return { venda, produtos, servicos };
    },
  });

  if (dados.isError) return <Banner tom="perigo">Não foi possível carregar a venda. Recarregue a página.</Banner>;
  if (dados.isPending) return <p className="text-muted-foreground">Carregando...</p>;

  const { venda, produtos, servicos } = dados.data;
  if (!EDITAVEIS.includes(produtos.status) || !EDITAVEIS.includes(servicos.status)) {
    return <Banner tom="perigo">Esta venda não pode mais ser editada: nenhuma das duas notas pode já ter sido emitida.</Banner>;
  }

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Editar venda</h1>
      <Card>
        <CardHeader><CardTitle>Dados da venda</CardTitle></CardHeader>
        <CardContent><VendaForm editar={{ venda, produtos, servicos }} /></CardContent>
      </Card>
    </div>
  );
}
