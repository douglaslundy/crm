'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { clientesApi, consultarCep, paraPayload, QUERY_KEY_CLIENTES } from '../api';
import { clienteSchema, type ClienteFormDados } from '../schemas';
import type { Cliente } from '../types';

const VAZIO: ClienteFormDados = {
  tipo: 'PF', nome: '', cpf_cnpj: '', inscricao_estadual: '', ie_isento: false, email: '', telefone: '',
  logradouro: '', numero: '', bairro: '', cidade: '', uf: '', cep: '', origem: '',
};

function paraFormulario(c: Cliente): ClienteFormDados {
  return {
    tipo: c.tipo, nome: c.nome, cpf_cnpj: c.cpf_cnpj ?? '', inscricao_estadual: c.inscricao_estadual ?? '',
    ie_isento: c.ie_isento, email: c.email ?? '', telefone: c.telefone ?? '',
    logradouro: c.logradouro ?? '', numero: c.numero ?? '', bairro: c.bairro ?? '', cidade: c.cidade ?? '',
    uf: c.uf ?? '', cep: c.cep ?? '', origem: c.origem ?? '',
  };
}

export function ClienteForm({ cliente, onSalvar }: { cliente?: Cliente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ClienteFormDados>({ resolver: zodResolver(clienteSchema), defaultValues: cliente ? paraFormulario(cliente) : VAZIO });
  const tipo = useWatch({ control: form.control, name: 'tipo' });
  const salvar = useMutation({
    mutationFn: (d: ClienteFormDados) => (cliente ? clientesApi.editar(cliente.id, paraPayload(d)) : clientesApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(cliente ? 'Cliente atualizado.' : 'Cliente criado.');
      if (!cliente) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
      onSalvar?.();
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
        return;
      }
      const campos = Object.entries(erro.errors);
      if (campos.length === 0) {
        form.setError('root', { message: erro.message });
        return;
      }
      for (const [campo, mensagens] of campos) {
        form.setError(campo as keyof ClienteFormDados, { message: mensagens[0] });
      }
    },
  });
  const buscarCep = useMutation({
    mutationFn: (cep: string) => consultarCep(cep),
    onSuccess: ({ data }) => {
      form.setValue('logradouro', data.logradouro);
      form.setValue('bairro', data.bairro);
      form.setValue('cidade', data.cidade);
      form.setValue('uf', data.uf);
    },
    onError: () => toast.error('CEP não encontrado. Preencha o endereço manualmente.'),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="cliente_tipo" label="Tipo" erro={erros.tipo?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('tipo')}>
            <option value="PF">Pessoa física</option>
            <option value="PJ">Pessoa jurídica</option>
          </SelectNativo>
        )}
      </Campo>
      <Campo id="cliente_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="cliente_documento" label={tipo === 'PJ' ? 'CNPJ' : 'CPF'} dica="Pode ficar em branco por enquanto." erro={erros.cpf_cnpj?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cpf_cnpj')} />}
      </Campo>
      <Campo id="cliente_email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" {...form.register('email')} />}
      </Campo>
      <Campo id="cliente_telefone" label="Telefone" erro={erros.telefone?.message}>
        {(a11y) => <Input {...a11y} {...form.register('telefone')} />}
      </Campo>
      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Campo id="cliente_cep" label="CEP" erro={erros.cep?.message}>
            {(a11y) => <Input {...a11y} {...form.register('cep')} />}
          </Campo>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={buscarCep.isPending} onClick={() => buscarCep.mutate(form.getValues('cep') ?? '')}>
          Buscar
        </Button>
      </div>
      <Campo id="cliente_logradouro" label="Logradouro" erro={erros.logradouro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('logradouro')} />}
      </Campo>
      <Campo id="cliente_numero" label="Número" erro={erros.numero?.message}>
        {(a11y) => <Input {...a11y} {...form.register('numero')} />}
      </Campo>
      <Campo id="cliente_bairro" label="Bairro" erro={erros.bairro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('bairro')} />}
      </Campo>
      <Campo id="cliente_cidade" label="Cidade" erro={erros.cidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cidade')} />}
      </Campo>
      <Campo id="cliente_uf" label="UF" erro={erros.uf?.message}>
        {(a11y) => <Input {...a11y} maxLength={2} {...form.register('uf')} />}
      </Campo>
      <Campo id="cliente_origem" label="Origem" dica="Ex.: WhatsApp, indicação." erro={erros.origem?.message}>
        {(a11y) => <Input {...a11y} {...form.register('origem')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar cliente</Button>
    </form>
  );
}
