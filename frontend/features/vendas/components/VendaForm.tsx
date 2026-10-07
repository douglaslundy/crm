'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { clientesApi, QUERY_KEY_CLIENTES } from '@/features/clientes/api';
import { QUERY_KEY_NOTAS } from '@/features/notas/api';
import { ROTULO_PAGAMENTO, type FormaPagamento, type Nota } from '@/features/notas/types';
import { produtosApi, QUERY_KEY_PRODUTOS } from '@/features/produtos/api';
import { QUERY_KEY_SERVICOS, servicosApi } from '@/features/servicos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_VENDAS, vendasApi } from '../api';
import { ratearDesconto } from '../rateio';
import { paraPayloadDeVenda, vendaSchema, type VendaFormDados } from '../schemas';
import type { Venda } from '../types';

const PRODUTO_VAZIO = { produto_id: '', quantidade: '1', valor_unitario: '' };
const SERVICO_VAZIO = { servico_id: '', quantidade: '1', valor_unitario: '' };
const PAGAMENTO_VAZIO = { tpag: '01' as FormaPagamento, xpag: '', valor: '0,00' };
const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

/** A venda aberta para edição: o cabeçalho e as duas notas (itens, pagamentos e informações vêm delas). */
export interface VendaParaEditar {
  venda: Venda;
  produtos: Nota;
  servicos: Nota;
}

function paraFormulario({ venda, produtos, servicos }: VendaParaEditar): VendaFormDados {
  return {
    cliente_id: venda.cliente?.id ?? '',
    desconto: centavosParaReais(venda.desconto_centavos),
    informacoes_complementares: produtos.informacoes_complementares ?? '',
    itens_produtos: (produtos.itens ?? []).map((i) => ({
      produto_id: i.produto_id ?? '',
      quantidade: i.quantidade.replace('.', ','),
      valor_unitario: centavosParaReais(i.valor_unitario_centavos),
    })),
    itens_servicos: (servicos.itens ?? []).map((i) => ({
      servico_id: i.servico_id ?? '',
      quantidade: i.quantidade.replace('.', ','),
      valor_unitario: centavosParaReais(i.valor_unitario_centavos),
    })),
    pagamentos: (produtos.pagamentos ?? []).map((p) => ({ tpag: p.tpag, xpag: p.xpag ?? '', valor: centavosParaReais(p.valor_centavos) })),
  };
}

/** '2,5' → 2500. Só ajuda visual: a conta que vale é a do servidor. */
function milesimos(texto: string): number {
  if (!QUANTIDADE.test(texto)) return 0;
  const [inteiro, fracao = ''] = texto.replace(',', '.').split('.');
  return Number(inteiro) * 1000 + Number(fracao.padEnd(3, '0').slice(0, 3));
}

function totalDaLinha(quantidade: string, valorUnitario: string, precoDoCadastro: number): number {
  const preco = valorUnitario !== '' ? (DINHEIRO.test(valorUnitario) ? reaisParaCentavos(valorUnitario) : 0) : precoDoCadastro;
  return Math.trunc((milesimos(quantidade) * preco + 500) / 1000);
}

export function VendaForm({ editar }: { editar?: VendaParaEditar }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const clientes = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const produtos = useQuery({ queryKey: QUERY_KEY_PRODUTOS, queryFn: async () => (await produtosApi.listar()).data });
  const servicos = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data });

  const form = useForm<VendaFormDados>({
    resolver: zodResolver(vendaSchema),
    defaultValues: editar
      ? paraFormulario(editar)
      : { cliente_id: '', desconto: '0,00', informacoes_complementares: '', itens_produtos: [PRODUTO_VAZIO], itens_servicos: [SERVICO_VAZIO], pagamentos: [PAGAMENTO_VAZIO] },
  });
  const itensProdutos = useFieldArray({ control: form.control, name: 'itens_produtos' });
  const itensServicos = useFieldArray({ control: form.control, name: 'itens_servicos' });
  const pagamentos = useFieldArray({ control: form.control, name: 'pagamentos' });
  const produtosValores = useWatch({ control: form.control, name: 'itens_produtos' });
  const servicosValores = useWatch({ control: form.control, name: 'itens_servicos' });
  const pagamentosValores = useWatch({ control: form.control, name: 'pagamentos' });
  const desconto = useWatch({ control: form.control, name: 'desconto' });

  const salvar = useMutation({
    mutationFn: (d: VendaFormDados) => (editar ? vendasApi.editar(editar.venda.id, paraPayloadDeVenda(d)) : vendasApi.criar(paraPayloadDeVenda(d))),
    onSuccess: ({ data }) => {
      toast.success('Venda salva.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_VENDAS });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      router.push(`/vendas/${data.id}`);
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível salvar. Tente novamente.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  const listaClientes = (clientes.data ?? []).filter((c) => c.estagio === 'CLIENTE');
  const subtotalProdutos = (produtosValores ?? []).reduce((soma, i) => soma + totalDaLinha(i.quantidade, i.valor_unitario, produtos.data?.find((p) => p.id === i.produto_id)?.preco_centavos ?? 0), 0);
  const subtotalServicos = (servicosValores ?? []).reduce((soma, i) => soma + totalDaLinha(i.quantidade, i.valor_unitario, servicos.data?.find((s) => s.id === i.servico_id)?.preco_centavos ?? 0), 0);
  const descontoGeral = DINHEIRO.test(desconto) ? reaisParaCentavos(desconto) : 0;
  const somaPagamentos = (pagamentosValores ?? []).reduce((soma, p) => soma + (DINHEIRO.test(p.valor) ? reaisParaCentavos(p.valor) : 0), 0);

  let rateio: [number, number] | null = null;
  try {
    rateio = ratearDesconto(descontoGeral, [subtotalProdutos, subtotalServicos]);
  } catch {
    rateio = null;
  }
  const totalNfe = rateio === null ? null : subtotalProdutos - rateio[0];
  const totalNfse = rateio === null ? null : subtotalServicos - rateio[1];

  if (clientes.isError || produtos.isError || servicos.isError) {
    return <Banner tom="perigo">Não foi possível carregar clientes, produtos e serviços. Recarregue a página.</Banner>;
  }
  if (clientes.isPending || produtos.isPending || servicos.isPending) {
    return <p className="text-muted-foreground">Carregando...</p>;
  }

  return (
    <form noValidate className="space-y-4" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="venda_cliente" label="Cliente" dica="Destinatário da NF-e e tomador da NFS-e." erro={erros.cliente_id?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('cliente_id')}>
            <option value="">Escolha um cliente</option>
            {listaClientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
          </SelectNativo>
        )}
      </Campo>

      <fieldset className="space-y-3">
        <legend className="font-medium">Produtos (NF-e)</legend>
        {itensProdutos.fields.map((campo, i) => {
          const n = i + 1;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`venda_produto_${n}`} label={`Produto ${n}`} erro={erros.itens_produtos?.[i]?.produto_id?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`itens_produtos.${i}.produto_id`)}>
                    <option value="">Escolha um produto</option>
                    {(produtos.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              <Campo id={`venda_produto_${n}_quantidade`} label={`Quantidade do produto ${n}`} erro={erros.itens_produtos?.[i]?.quantidade?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`itens_produtos.${i}.quantidade`)} />}
              </Campo>
              <Campo id={`venda_produto_${n}_valor`} label={`Preço unitário do produto ${n}`} dica="Vazio usa o preço do cadastro" erro={erros.itens_produtos?.[i]?.valor_unitario?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="10,50" {...form.register(`itens_produtos.${i}.valor_unitario`)} />}
              </Campo>
              {itensProdutos.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover produto ${n}`} onClick={() => itensProdutos.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.itens_produtos?.message === 'string' ? <p className="text-sm text-danger">{erros.itens_produtos.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => itensProdutos.append(PRODUTO_VAZIO)}>Adicionar produto</Button>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="font-medium">Serviços (NFS-e)</legend>
        <p className="text-sm text-muted-foreground">Todos os serviços precisam ter o mesmo código de tributação e a mesma alíquota.</p>
        {itensServicos.fields.map((campo, i) => {
          const n = i + 1;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`venda_servico_${n}`} label={`Serviço ${n}`} erro={erros.itens_servicos?.[i]?.servico_id?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`itens_servicos.${i}.servico_id`)}>
                    <option value="">Escolha um serviço</option>
                    {(servicos.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              <Campo id={`venda_servico_${n}_quantidade`} label={`Quantidade do serviço ${n}`} erro={erros.itens_servicos?.[i]?.quantidade?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`itens_servicos.${i}.quantidade`)} />}
              </Campo>
              <Campo id={`venda_servico_${n}_valor`} label={`Preço unitário do serviço ${n}`} dica="Vazio usa o preço do cadastro" erro={erros.itens_servicos?.[i]?.valor_unitario?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="150,00" {...form.register(`itens_servicos.${i}.valor_unitario`)} />}
              </Campo>
              {itensServicos.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover serviço ${n}`} onClick={() => itensServicos.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.itens_servicos?.message === 'string' ? <p className="text-sm text-danger">{erros.itens_servicos.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => itensServicos.append(SERVICO_VAZIO)}>Adicionar serviço</Button>
      </fieldset>

      <Campo id="venda_desconto" label="Desconto (R$)" dica="Desconto geral: é dividido entre a NF-e e a NFS-e em proporção aos totais." erro={erros.desconto?.message}>
        {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('desconto')} />}
      </Campo>

      <fieldset className="space-y-3">
        <legend className="font-medium">Pagamentos (NF-e)</legend>
        {pagamentos.fields.map((campo, i) => {
          const n = i + 1;
          const forma = pagamentosValores?.[i]?.tpag;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`venda_pag_${n}_forma`} label={`Forma de pagamento ${n}`} erro={erros.pagamentos?.[i]?.tpag?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`pagamentos.${i}.tpag`)}>
                    {(Object.keys(ROTULO_PAGAMENTO) as FormaPagamento[]).map((f) => <option key={f} value={f}>{ROTULO_PAGAMENTO[f]}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              {forma === '99' ? (
                <Campo id={`venda_pag_${n}_descricao`} label={`Descrição do pagamento ${n}`} erro={erros.pagamentos?.[i]?.xpag?.message}>
                  {(a11y) => <Input {...a11y} {...form.register(`pagamentos.${i}.xpag`)} />}
                </Campo>
              ) : null}
              <Campo id={`venda_pag_${n}_valor`} label={`Valor do pagamento ${n}`} erro={erros.pagamentos?.[i]?.valor?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`pagamentos.${i}.valor`)} />}
              </Campo>
              {pagamentos.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover pagamento ${n}`} onClick={() => pagamentos.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.pagamentos?.message === 'string' ? <p className="text-sm text-danger">{erros.pagamentos.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => pagamentos.append(PAGAMENTO_VAZIO)}>Adicionar pagamento</Button>
      </fieldset>

      <Campo id="venda_info" label="Informações complementares" dica="Até 255 caracteres; valem para as duas notas." erro={erros.informacoes_complementares?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('informacoes_complementares')} />}
      </Campo>

      <div className="space-y-1 text-sm">
        {rateio === null || totalNfe === null || totalNfse === null ? (
          <p className="text-danger">O desconto não pode passar do total dos itens.</p>
        ) : (
          <>
            <p>NF-e: desconto {formatarCentavos(rateio[0])}, total {formatarCentavos(totalNfe)}</p>
            <p>NFS-e: desconto {formatarCentavos(rateio[1])}, total {formatarCentavos(totalNfse)}</p>
            <p className="font-medium">Total da venda: {formatarCentavos(totalNfe + totalNfse)}</p>
            <p className="text-muted-foreground">Os pagamentos devem somar o total da NF-e ({formatarCentavos(totalNfe)}).</p>
            {somaPagamentos !== totalNfe ? (
              <p className="text-warning">
                Pagamentos somam {formatarCentavos(somaPagamentos)} (diferença de {formatarCentavos(Math.abs(totalNfe - somaPagamentos))}).
              </p>
            ) : null}
          </>
        )}
      </div>

      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar rascunho</Button>
    </form>
  );
}
