'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useFieldArray, useForm, useWatch, type FieldPath } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { clientesApi, QUERY_KEY_CLIENTES } from '@/features/clientes/api';
import { produtosApi, QUERY_KEY_PRODUTOS } from '@/features/produtos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { notaSchema, paraPayload, type NotaFormDados } from '../schemas';
import { ROTULO_PAGAMENTO, type FormaPagamento, type Nota } from '../types';

const ITEM_VAZIO = { produto_id: '', quantidade: '1', valor_unitario: '' };
const PAGAMENTO_VAZIO = { tpag: '01' as FormaPagamento, xpag: '', valor: '0,00' };
const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

function paraFormulario(n: Nota): NotaFormDados {
  return {
    cliente_id: n.cliente?.id ?? '',
    consumidor_final: n.consumidor_final === null ? '' : n.consumidor_final ? 'sim' : 'nao',
    desconto: centavosParaReais(n.desconto_centavos),
    informacoes_complementares: n.informacoes_complementares ?? '',
    itens: (n.itens ?? []).map((i) => ({
      produto_id: i.produto_id,
      quantidade: i.quantidade.replace('.', ','),
      valor_unitario: centavosParaReais(i.valor_unitario_centavos),
    })),
    pagamentos: (n.pagamentos ?? []).map((p) => ({ tpag: p.tpag, xpag: p.xpag ?? '', valor: centavosParaReais(p.valor_centavos) })),
  };
}

/** '2,5' → 2500. Só ajuda visual: a conta que vale é a do servidor. */
function milesimos(texto: string): number {
  if (!QUANTIDADE.test(texto)) return 0;
  const [inteiro, fracao = ''] = texto.replace(',', '.').split('.');
  return Number(inteiro) * 1000 + Number(fracao.padEnd(3, '0').slice(0, 3));
}

/** Nome do campo da API → nome do campo do formulário, ou null se não houver campo correspondente. */
function campoDoFormulario(campoApi: string): FieldPath<NotaFormDados> | null {
  const nome = campoApi
    .replace(/^desconto_centavos$/, 'desconto')
    .replace(/\.valor_unitario_centavos$/, '.valor_unitario')
    .replace(/\.valor_centavos$/, '.valor');
  const conhecido = /^(cliente_id|consumidor_final|desconto|informacoes_complementares|itens|pagamentos|itens\.\d+\.(produto_id|quantidade|valor_unitario)|pagamentos\.\d+\.(tpag|xpag|valor))$/;
  return conhecido.test(nome) ? (nome as FieldPath<NotaFormDados>) : null;
}

export function NotaForm({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const clientes = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const produtos = useQuery({ queryKey: QUERY_KEY_PRODUTOS, queryFn: async () => (await produtosApi.listar()).data });

  const form = useForm<NotaFormDados>({
    resolver: zodResolver(notaSchema),
    defaultValues: nota
      ? paraFormulario(nota)
      : { cliente_id: '', consumidor_final: '', desconto: '0,00', informacoes_complementares: '', itens: [ITEM_VAZIO], pagamentos: [PAGAMENTO_VAZIO] },
  });
  const itens = useFieldArray({ control: form.control, name: 'itens' });
  const pagamentos = useFieldArray({ control: form.control, name: 'pagamentos' });
  const clienteId = useWatch({ control: form.control, name: 'cliente_id' });
  const itensValores = useWatch({ control: form.control, name: 'itens' });
  const pagamentosValores = useWatch({ control: form.control, name: 'pagamentos' });
  const desconto = useWatch({ control: form.control, name: 'desconto' });

  const salvar = useMutation({
    mutationFn: (d: NotaFormDados) => (nota ? notasApi.editar(nota.id, paraPayload(d)) : notasApi.criar(paraPayload(d))),
    onSuccess: ({ data }) => {
      toast.success('Rascunho salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      onSalvo?.(data);
      if (!nota) router.push(`/notas/${data.id}`);
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
        return;
      }
      let algumCampo = false;
      for (const [campoApi, mensagens] of Object.entries(erro.errors)) {
        const campo = campoDoFormulario(campoApi);
        if (campo !== null) {
          form.setError(campo, { message: mensagens[0] });
          algumCampo = true;
        }
      }
      if (!algumCampo || erro.codigo !== undefined) form.setError('root', { message: erro.primeiraMensagem() });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  const listaClientes = (clientes.data ?? []).filter((c) => c.estagio === 'CLIENTE');
  const clienteEhPj = listaClientes.find((c) => c.id === clienteId)?.tipo === 'PJ';

  const subtotal = (itensValores ?? []).reduce((soma, i) => {
    const preco = i.valor_unitario !== '' ? (DINHEIRO.test(i.valor_unitario) ? reaisParaCentavos(i.valor_unitario) : 0) : (produtos.data?.find((p) => p.id === i.produto_id)?.preco_centavos ?? 0);
    return soma + Math.trunc((milesimos(i.quantidade) * preco + 500) / 1000);
  }, 0);
  const total = subtotal - (DINHEIRO.test(desconto) ? reaisParaCentavos(desconto) : 0);
  const somaPagamentos = (pagamentosValores ?? []).reduce((soma, p) => soma + (DINHEIRO.test(p.valor) ? reaisParaCentavos(p.valor) : 0), 0);

  if (clientes.isError || produtos.isError) {
    return <p role="alert" className="text-sm text-danger">Não foi possível carregar clientes e produtos. Recarregue a página.</p>;
  }
  if (clientes.isPending || produtos.isPending) {
    return <p className="text-muted-foreground">Carregando...</p>;
  }

  return (
    <form noValidate className="space-y-4" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="nota_cliente" label="Cliente" erro={erros.cliente_id?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('cliente_id')}>
            <option value="">Escolha um cliente</option>
            {listaClientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
          </SelectNativo>
        )}
      </Campo>

      {clienteEhPj ? (
        <Campo id="nota_consumidor_final" label="Consumidor final?" dica="Cliente pessoa jurídica: informe se a venda é para consumo próprio." erro={erros.consumidor_final?.message}>
          {(a11y) => (
            <SelectNativo {...a11y} {...form.register('consumidor_final')}>
              <option value="">Escolha</option>
              <option value="sim">Sim</option>
              <option value="nao">Não</option>
            </SelectNativo>
          )}
        </Campo>
      ) : null}

      <fieldset className="space-y-3">
        <legend className="font-medium">Itens</legend>
        {itens.fields.map((campo, i) => {
          const n = i + 1;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`nota_item_${n}_produto`} label={`Produto ${n}`} erro={erros.itens?.[i]?.produto_id?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`itens.${i}.produto_id`)}>
                    <option value="">Escolha um produto</option>
                    {(produtos.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              <Campo id={`nota_item_${n}_quantidade`} label={`Quantidade ${n}`} erro={erros.itens?.[i]?.quantidade?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`itens.${i}.quantidade`)} />}
              </Campo>
              <Campo id={`nota_item_${n}_valor`} label={`Preço unitário ${n}`} dica="Vazio usa o preço do cadastro" erro={erros.itens?.[i]?.valor_unitario?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="10,50" {...form.register(`itens.${i}.valor_unitario`)} />}
              </Campo>
              {itens.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover item ${n}`} onClick={() => itens.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.itens?.message === 'string' ? <p className="text-sm text-danger">{erros.itens.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => itens.append(ITEM_VAZIO)}>Adicionar item</Button>
      </fieldset>

      <Campo id="nota_desconto" label="Desconto (R$)" erro={erros.desconto?.message}>
        {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('desconto')} />}
      </Campo>

      <fieldset className="space-y-3">
        <legend className="font-medium">Pagamentos</legend>
        {pagamentos.fields.map((campo, i) => {
          const n = i + 1;
          const forma = pagamentosValores?.[i]?.tpag;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`nota_pag_${n}_forma`} label={`Forma de pagamento ${n}`} erro={erros.pagamentos?.[i]?.tpag?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`pagamentos.${i}.tpag`)}>
                    {(Object.keys(ROTULO_PAGAMENTO) as FormaPagamento[]).map((f) => <option key={f} value={f}>{ROTULO_PAGAMENTO[f]}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              {forma === '99' ? (
                <Campo id={`nota_pag_${n}_descricao`} label={`Descrição do pagamento ${n}`} erro={erros.pagamentos?.[i]?.xpag?.message}>
                  {(a11y) => <Input {...a11y} {...form.register(`pagamentos.${i}.xpag`)} />}
                </Campo>
              ) : null}
              <Campo id={`nota_pag_${n}_valor`} label={`Valor do pagamento ${n}`} erro={erros.pagamentos?.[i]?.valor?.message}>
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

      <Campo id="nota_info" label="Informações complementares" erro={erros.informacoes_complementares?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('informacoes_complementares')} />}
      </Campo>

      <div className="space-y-1 text-sm">
        <p className="font-medium">Total: {formatarCentavos(total)}</p>
        {somaPagamentos !== total ? (
          <p className="text-warning">
            Pagamentos somam {formatarCentavos(somaPagamentos)} (diferença de {formatarCentavos(Math.abs(total - somaPagamentos))}).
          </p>
        ) : null}
      </div>

      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar rascunho</Button>
    </form>
  );
}
