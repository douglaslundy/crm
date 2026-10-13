'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { produtosApi, QUERY_KEY_PRODUTOS } from '@/features/produtos/api';
import { servicosApi, QUERY_KEY_SERVICOS } from '@/features/servicos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { ItemEntrada, Negocio, TipoDeItem } from '../types';

interface OpcaoDeCatalogo {
  chave: string;
  tipo: TipoDeItem;
  id: string;
  rotulo: string;
}

function paraEntrada(itens: Negocio['itens']): ItemEntrada[] {
  return itens.map((i) => ({ tipo: i.tipo, item_id: i.item_id, quantidade: i.quantidade }));
}

export function EditorDeItens({ negocio, podeEditar }: { negocio: Negocio; podeEditar: boolean }) {
  const queryClient = useQueryClient();
  const aberto = negocio.status === 'ABERTO' && podeEditar;
  const [escolha, setEscolha] = useState('');
  const [quantidade, setQuantidade] = useState('1');
  const [erroValor, setErroValor] = useState<string | null>(null);

  const produtos = useQuery({ queryKey: QUERY_KEY_PRODUTOS, queryFn: async () => (await produtosApi.listar()).data, enabled: aberto });
  const servicos = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data, enabled: aberto });

  const opcoes: OpcaoDeCatalogo[] = [
    ...(produtos.data ?? []).map((p): OpcaoDeCatalogo => ({ chave: `PRODUTO:${p.id}`, tipo: 'PRODUTO', id: p.id, rotulo: `Produto: ${p.nome}` })),
    ...(servicos.data ?? []).map((s): OpcaoDeCatalogo => ({ chave: `SERVICO:${s.id}`, tipo: 'SERVICO', id: s.id, rotulo: `Serviço: ${s.nome}` })),
  ];

  const invalida = () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
  const aoErro = (erro: unknown) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.');
  const definir = useMutation({
    mutationFn: (itens: ItemEntrada[]) => crmApi.definirItens(negocio.id, itens),
    onSuccess: () => void invalida(),
    onError: aoErro,
  });
  const salvarValor = useMutation({
    mutationFn: (centavos: number) => crmApi.editarNegocio(negocio.id, { valor_centavos: centavos }),
    onSuccess: () => void invalida(),
    onError: aoErro,
  });

  function adicionar() {
    const opcao = opcoes.find((o) => o.chave === escolha);
    const qtd = Number(quantidade);
    if (!opcao || !Number.isInteger(qtd) || qtd < 1) return;
    definir.mutate([...paraEntrada(negocio.itens), { tipo: opcao.tipo, item_id: opcao.id, quantidade: qtd }]);
    setEscolha('');
    setQuantidade('1');
  }

  return (
    <div className="space-y-3">
      {negocio.itens.length === 0 ? (
        <p className="text-sm text-muted-foreground">Sem itens. O valor do negócio é o valor digitado.</p>
      ) : (
        <>
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-muted-foreground">
              <tr>
                <th className="py-1 pr-3 font-medium">Item</th>
                <th className="py-1 pr-3 font-medium">Qtd.</th>
                <th className="py-1 pr-3 font-medium">Unitário</th>
                <th className="py-1 font-medium">Subtotal</th>
                {aberto ? <th className="py-1"><span className="sr-only">Ações</span></th> : null}
              </tr>
            </thead>
            <tbody className="divide-y">
              {negocio.itens.map((item) => (
                <tr key={item.id}>
                  <td className="py-1.5 pr-3">{item.descricao}</td>
                  <td className="py-1.5 pr-3">{item.quantidade}</td>
                  <td className="py-1.5 pr-3">{formatarCentavos(item.valor_unitario_centavos)}</td>
                  <td className="py-1.5">{formatarCentavos(item.quantidade * item.valor_unitario_centavos)}</td>
                  {aberto ? (
                    <td className="py-1.5 text-right">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={definir.isPending}
                        aria-label={`Remover ${item.descricao}`}
                        onClick={() => definir.mutate(paraEntrada(negocio.itens.filter((i) => i.id !== item.id)))}
                      >
                        Remover
                      </Button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
          <p className="text-xs text-muted-foreground">O valor do negócio é a soma dos itens.</p>
        </>
      )}

      {aberto ? (
        <div className="flex flex-wrap items-end gap-2 border-t pt-3">
          <div className="min-w-48 flex-1 space-y-1">
            <Label htmlFor="item-do-catalogo">Item do catálogo</Label>
            <select
              id="item-do-catalogo"
              className="w-full rounded-md border border-border bg-background px-2 py-2 text-sm"
              value={escolha}
              onChange={(e) => setEscolha(e.target.value)}
            >
              <option value="">Escolha...</option>
              {opcoes.map((o) => (
                <option key={o.chave} value={o.chave}>{o.rotulo}</option>
              ))}
            </select>
          </div>
          <div className="w-24 space-y-1">
            <Label htmlFor="quantidade-do-item">Quantidade</Label>
            <Input id="quantidade-do-item" type="number" min={1} value={quantidade} onChange={(e) => setQuantidade(e.target.value)} />
          </div>
          <Button type="button" disabled={escolha === '' || definir.isPending} onClick={adicionar}>
            Adicionar item
          </Button>
        </div>
      ) : null}

      {aberto && negocio.itens.length === 0 ? (
        <div className="max-w-48 space-y-1">
          <Label htmlFor="valor-do-negocio">Valor do negócio (R$)</Label>
          <Input
            id="valor-do-negocio"
            inputMode="decimal"
            defaultValue={centavosParaReais(negocio.valor_centavos)}
            disabled={salvarValor.isPending}
            aria-invalid={erroValor !== null}
            aria-describedby={erroValor ? 'valor-do-negocio-erro' : undefined}
            onBlur={(e) => {
              const texto = e.target.value.trim();
              if (!/^\d+(,\d{1,2})?$/.test(texto)) {
                setErroValor('Use o formato 99,90.');
                return;
              }
              setErroValor(null);
              if (reaisParaCentavos(texto) !== negocio.valor_centavos) salvarValor.mutate(reaisParaCentavos(texto));
            }}
          />
          {erroValor ? <p id="valor-do-negocio-erro" className="text-xs text-danger">{erroValor}</p> : null}
        </div>
      ) : null}
    </div>
  );
}
