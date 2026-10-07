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
import { QUERY_KEY_SERVICOS, servicosApi } from '@/features/servicos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { notaDeServicoSchema, paraPayloadDeServico, type NotaDeServicoDados } from '../schemas';
import type { Nota } from '../types';

const ITEM_VAZIO = { servico_id: '', quantidade: '1', valor_unitario: '' };
const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

function paraFormulario(n: Nota): NotaDeServicoDados {
  return {
    cliente_id: n.cliente?.id ?? '',
    desconto: centavosParaReais(n.desconto_centavos),
    informacoes_complementares: n.informacoes_complementares ?? '',
    itens: (n.itens ?? []).map((i) => ({
      servico_id: i.servico_id ?? '',
      quantidade: i.quantidade.replace('.', ','),
      valor_unitario: centavosParaReais(i.valor_unitario_centavos),
    })),
  };
}

/** '2,5' → 2500. Só ajuda visual: a conta que vale é a do servidor. */
function milesimos(texto: string): number {
  if (!QUANTIDADE.test(texto)) return 0;
  const [inteiro, fracao = ''] = texto.replace(',', '.').split('.');
  return Number(inteiro) * 1000 + Number(fracao.padEnd(3, '0').slice(0, 3));
}

export function NotaDeServicoForm({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const clientes = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const servicos = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data });

  const form = useForm<NotaDeServicoDados>({
    resolver: zodResolver(notaDeServicoSchema),
    defaultValues: nota ? paraFormulario(nota) : { cliente_id: '', desconto: '0,00', informacoes_complementares: '', itens: [ITEM_VAZIO] },
  });
  const itens = useFieldArray({ control: form.control, name: 'itens' });
  const itensValores = useWatch({ control: form.control, name: 'itens' });
  const desconto = useWatch({ control: form.control, name: 'desconto' });

  const salvar = useMutation({
    mutationFn: (d: NotaDeServicoDados) => (nota ? notasApi.editar(nota.id, paraPayloadDeServico(d)) : notasApi.criar(paraPayloadDeServico(d))),
    onSuccess: ({ data }) => {
      toast.success('Rascunho salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      onSalvo?.(data);
      if (!nota) router.push(`/notas/${data.id}`);
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível salvar. Tente novamente.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  const listaClientes = (clientes.data ?? []).filter((c) => c.estagio === 'CLIENTE');
  const subtotal = (itensValores ?? []).reduce((soma, i) => {
    const preco = i.valor_unitario !== '' ? (DINHEIRO.test(i.valor_unitario) ? reaisParaCentavos(i.valor_unitario) : 0) : (servicos.data?.find((s) => s.id === i.servico_id)?.preco_centavos ?? 0);
    return soma + Math.trunc((milesimos(i.quantidade) * preco + 500) / 1000);
  }, 0);
  const total = subtotal - (DINHEIRO.test(desconto) ? reaisParaCentavos(desconto) : 0);

  if (clientes.isError || servicos.isError) {
    return <Banner tom="perigo">Não foi possível carregar clientes e serviços. Recarregue a página.</Banner>;
  }
  if (clientes.isPending || servicos.isPending) {
    return <p className="text-muted-foreground">Carregando...</p>;
  }

  return (
    <form noValidate className="space-y-4" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="nota_cliente" label="Cliente" dica="O tomador do serviço." erro={erros.cliente_id?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('cliente_id')}>
            <option value="">Escolha um cliente</option>
            {listaClientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
          </SelectNativo>
        )}
      </Campo>

      <fieldset className="space-y-3">
        <legend className="font-medium">Serviços</legend>
        <p className="text-sm text-muted-foreground">Todos os serviços da nota precisam ter o mesmo código de tributação e a mesma alíquota.</p>
        {itens.fields.map((campo, i) => {
          const n = i + 1;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`nota_item_${n}_servico`} label={`Serviço ${n}`} erro={erros.itens?.[i]?.servico_id?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`itens.${i}.servico_id`)}>
                    <option value="">Escolha um serviço</option>
                    {(servicos.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              <Campo id={`nota_item_${n}_quantidade`} label={`Quantidade ${n}`} erro={erros.itens?.[i]?.quantidade?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`itens.${i}.quantidade`)} />}
              </Campo>
              <Campo id={`nota_item_${n}_valor`} label={`Preço unitário ${n}`} dica="Vazio usa o preço do cadastro" erro={erros.itens?.[i]?.valor_unitario?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="150,00" {...form.register(`itens.${i}.valor_unitario`)} />}
              </Campo>
              {itens.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover serviço ${n}`} onClick={() => itens.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.itens?.message === 'string' ? <p className="text-sm text-danger">{erros.itens.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => itens.append(ITEM_VAZIO)}>Adicionar serviço</Button>
      </fieldset>

      <Campo id="nota_desconto" label="Desconto (R$)" erro={erros.desconto?.message}>
        {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('desconto')} />}
      </Campo>

      <Campo id="nota_info" label="Informações complementares" dica="Até 255 caracteres." erro={erros.informacoes_complementares?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('informacoes_complementares')} />}
      </Campo>

      <p className="text-sm font-medium">Total: {formatarCentavos(total)}</p>

      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar rascunho</Button>
    </form>
  );
}
