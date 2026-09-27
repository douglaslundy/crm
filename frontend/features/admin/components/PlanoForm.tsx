'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useForm, useWatch } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { MODULOS, RECURSOS, ROTULO_MODULO, ROTULO_RECURSO, type Plano } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { campoDoFormulario, formParaEntrada, planoFormSchema, planoParaForm, type PlanoFormDados } from '../planos';
import type { PlanoEntrada } from '../types';

interface Props {
  inicial?: Plano;
  rotuloBotao: string;
  onSalvar: (entrada: PlanoEntrada) => Promise<unknown>;
}

export function PlanoForm({ inicial, rotuloBotao, onSalvar }: Props) {
  const form = useForm<PlanoFormDados>({ resolver: zodResolver(planoFormSchema), defaultValues: planoParaForm(inicial) });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;
  const politica = useWatch({ control: form.control, name: 'politica_excedente' });
  const limites = useWatch({ control: form.control, name: 'limites' });

  const enviar = envioUnico(
    form.handleSubmit(async (dados) => {
      try {
        await onSalvar(formParaEntrada(dados));
      } catch (erro) {
        if (!(erro instanceof ApiError)) {
          form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
          return;
        }
        const campos = Object.entries(erro.errors);
        if (campos.length === 0) form.setError('root', { message: erro.message });
        for (const [campo, mensagens] of campos) {
          form.setError(campoDoFormulario(campo) ?? 'root', { message: mensagens[0] });
        }
      }
    }),
  );

  return (
    <form onSubmit={enviar} noValidate className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo id="nome" label="Nome" erro={erros.nome?.message}>
          {(a11y) => <Input {...a11y} {...form.register('nome')} />}
        </Campo>
        <Campo id="ordem" label="Ordem de exibição" erro={erros.ordem?.message}>
          {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('ordem', { valueAsNumber: true })} />}
        </Campo>
        <div className="sm:col-span-2">
          <Campo id="descricao" label="Descrição" erro={erros.descricao?.message}>
            {(a11y) => <TextareaNativo {...a11y} rows={2} {...form.register('descricao')} />}
          </Campo>
        </div>
        <Campo id="preco_mensal" label="Preço mensal (R$)" erro={erros.preco_mensal?.message}>
          {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="99,90" {...form.register('preco_mensal')} />}
        </Campo>
        <Campo id="preco_anual" label="Preço anual (R$)" erro={erros.preco_anual?.message} dica="Opcional.">
          {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('preco_anual')} />}
        </Campo>
        <Campo id="dias_teste" label="Dias de teste grátis" erro={erros.dias_teste?.message} dica="0 = sem teste: a conta nasce aguardando ativação.">
          {(a11y) => <Input {...a11y} type="number" min={0} max={90} {...form.register('dias_teste', { valueAsNumber: true })} />}
        </Campo>
        <Campo id="politica_excedente" label="Política de excedente" erro={erros.politica_excedente?.message}>
          {(a11y) => (
            <SelectNativo {...a11y} {...form.register('politica_excedente')}>
              <option value="BLOQUEAR">Bloquear ao atingir o limite</option>
              <option value="COBRAR">Cobrar por documento excedente</option>
            </SelectNativo>
          )}
        </Campo>
        {politica === 'COBRAR' ? (
          <Campo id="preco_excedente" label="Preço por documento excedente (R$)" erro={erros.preco_excedente?.message}>
            {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('preco_excedente')} />}
          </Campo>
        ) : null}
      </div>

      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="checkbox" {...form.register('ativo')} /> Ativo</label>
        <label className="flex items-center gap-2"><input type="checkbox" {...form.register('visivel')} /> Visível na página de planos</label>
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Módulos</legend>
        <div className="flex flex-wrap gap-4 text-sm">
          {MODULOS.map((m) => (
            <label key={m} className="flex items-center gap-2">
              <input type="checkbox" value={m} {...form.register('modulos')} /> {ROTULO_MODULO[m]}
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Limites</legend>
        {RECURSOS.map((r) => {
          const erro = erros.limites?.[r]?.valor?.message;
          return (
            <div key={r} role="group" aria-label={ROTULO_RECURSO[r]} className="grid grid-cols-[1fr_7rem_auto] items-center gap-2">
              <span className="text-sm">{ROTULO_RECURSO[r]}</span>
              {limites[r].ilimitado ? (
                <span className="text-sm text-muted-foreground">Ilimitado</span>
              ) : (
                <Input
                  type="number"
                  min={0}
                  aria-label="Quantidade"
                  aria-invalid={Boolean(erro)}
                  {...form.register(`limites.${r}.valor`, { valueAsNumber: true })}
                />
              )}
              <label className="flex items-center gap-1 text-sm">
                <input type="checkbox" {...form.register(`limites.${r}.ilimitado`)} /> Ilimitado
              </label>
              {erro ? <p className="col-span-full text-sm text-danger">{erro}</p> : null}
            </div>
          );
        })}
        <p className="text-xs text-muted-foreground">0 bloqueia o recurso. Recursos de módulos ainda não lançados já podem ser configurados.</p>
      </fieldset>

      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? 'Salvando...' : rotuloBotao}
      </Button>
    </form>
  );
}
