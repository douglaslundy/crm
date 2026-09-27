'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { mascararCnpj, normalizarCnpj, validarCnpj } from '@/lib/cnpj';
import { formatarCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { cadastroApi, type CadastroEntrada } from '../api';
import { forcaDaSenha } from '../forcaDaSenha';
import { usePlanosPublicos } from '../hooks/usePlanosPublicos';
import { CAMPOS_EMPRESA, cadastroSchema, type CadastroDados } from '../schemas';

const VALORES_INICIAIS: CadastroDados = {
  cnpj: '', razao_social: '', nome_fantasia: '', responsavel_nome: '', email: '',
  password: '', password_confirmation: '', aceite_termos: false,
};

export function CadastroForm({ planoId }: { planoId: string }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [etapa, setEtapa] = useState<1 | 2>(1);
  const [avisoCnpj, setAvisoCnpj] = useState<string | null>(null);
  const ultimoConsultado = useRef<string | null>(null);
  const planos = usePlanosPublicos();
  const plano = planos.data?.find((p) => p.id === planoId);
  const form = useForm<CadastroDados>({ resolver: zodResolver(cadastroSchema), defaultValues: VALORES_INICIAIS });

  const consulta = useMutation({
    mutationFn: (cnpj: string) => cadastroApi.consultarCnpj(cnpj),
    onSuccess: ({ data }) => {
      setAvisoCnpj(null);
      form.setValue('razao_social', data.razao_social, { shouldValidate: true });
      form.setValue('nome_fantasia', data.nome_fantasia ?? '');
    },
    // A consulta é só conveniência: qualquer falha libera o preenchimento manual.
    onError: (erro) =>
      setAvisoCnpj(
        erro instanceof ApiError && erro.status === 404
          ? 'CNPJ não encontrado na base pública. Preencha os dados da empresa.'
          : 'Não foi possível buscar os dados agora. Preencha manualmente.',
      ),
  });

  const cadastro = useMutation({
    mutationFn: (dados: CadastroEntrada) => cadastroApi.cadastrar(dados),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(QUERY_KEY_USUARIO, data);
      router.push('/dashboard');
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError) || Object.keys(erro.errors).length === 0) {
        form.setError('root', {
          message: erro instanceof ApiError ? erro.message : 'Não foi possível concluir o cadastro. Tente novamente.',
        });
        return;
      }
      const campos = Object.keys(VALORES_INICIAIS);
      for (const [campo, mensagens] of Object.entries(erro.errors)) {
        if (campos.includes(campo)) form.setError(campo as keyof CadastroDados, { message: mensagens[0] });
        else form.setError('root', { message: mensagens[0] });
      }
      if (Object.keys(erro.errors).some((c) => (CAMPOS_EMPRESA as readonly string[]).includes(c))) setEtapa(1);
    },
  });

  function aoMudarCnpj(valor: string, onChange: (v: string) => void) {
    const mascarado = mascararCnpj(valor);
    onChange(mascarado);
    const normalizado = normalizarCnpj(mascarado);
    if (validarCnpj(normalizado) && ultimoConsultado.current !== normalizado) {
      ultimoConsultado.current = normalizado;
      consulta.mutate(normalizado);
    }
  }

  async function avancar() {
    if (await form.trigger([...CAMPOS_EMPRESA])) setEtapa(2);
  }

  const envioUnico = useEnvioUnico();
  const enviar = envioUnico(
    form.handleSubmit(async (d) => {
      await cadastro
        .mutateAsync({
          ...d,
          cnpj: normalizarCnpj(d.cnpj),
          nome_fantasia: d.nome_fantasia.trim() === '' ? null : d.nome_fantasia.trim(),
          plano_id: planoId,
        })
        .catch(() => undefined);
    }),
  );

  if (planos.isSuccess && !plano) {
    return (
      <div className="space-y-2 text-center">
        <p role="alert">Plano não encontrado ou indisponível.</p>
        <Link href="/planos" className="underline">Ver planos</Link>
      </div>
    );
  }

  const erros = form.formState.errors;
  const senha = form.watch('password');
  const forca = forcaDaSenha(senha);
  const enviando = form.formState.isSubmitting;

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {plano ? (
        <p className="rounded-md bg-accent p-3 text-sm">
          Plano escolhido: <strong>{plano.nome}</strong>, {formatarCentavos(plano.preco_mensal_centavos)}/mês
          {plano.dias_teste > 0 ? ` · ${plano.dias_teste} dias grátis` : ''}.{' '}
          <Link href="/planos" className="underline">Trocar</Link>
        </p>
      ) : null}

      <p className="text-sm text-muted-foreground">Etapa {etapa} de 2: {etapa === 1 ? 'empresa' : 'responsável'}</p>

      {etapa === 1 ? (
        <>
          <Campo id="cnpj" label="CNPJ" erro={erros.cnpj?.message} dica={consulta.isPending ? 'Buscando dados...' : avisoCnpj ?? undefined}>
            {(a11y) => (
              <Controller
                control={form.control}
                name="cnpj"
                render={({ field }) => (
                  <Input
                    {...a11y}
                    ref={field.ref}
                    name={field.name}
                    value={field.value}
                    onBlur={field.onBlur}
                    onChange={(e) => aoMudarCnpj(e.target.value, field.onChange)}
                    autoComplete="off"
                    placeholder="00.000.000/0000-00"
                  />
                )}
              />
            )}
          </Campo>
          <Campo id="razao_social" label="Razão social" erro={erros.razao_social?.message}>
            {(a11y) => <Input {...a11y} autoComplete="organization" {...form.register('razao_social')} />}
          </Campo>
          <Campo id="nome_fantasia" label="Nome fantasia" erro={erros.nome_fantasia?.message}>
            {(a11y) => <Input {...a11y} {...form.register('nome_fantasia')} />}
          </Campo>
          <Button type="button" className="w-full" onClick={() => void avancar()}>Continuar</Button>
        </>
      ) : (
        <>
          <Campo id="responsavel_nome" label="Seu nome" erro={erros.responsavel_nome?.message}>
            {(a11y) => <Input {...a11y} autoComplete="name" {...form.register('responsavel_nome')} />}
          </Campo>
          <Campo id="email" label="E-mail" erro={erros.email?.message}>
            {(a11y) => <Input {...a11y} type="email" autoComplete="email" {...form.register('email')} />}
          </Campo>
          <Campo
            id="password"
            label="Senha"
            erro={erros.password?.message}
            dica={forca.rotulo ? `Força da senha: ${forca.rotulo}` : 'Mínimo de 8 caracteres, com maiúscula, minúscula e número.'}
          >
            {(a11y) => <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password')} />}
          </Campo>
          <Campo id="password_confirmation" label="Confirmar senha" erro={erros.password_confirmation?.message}>
            {(a11y) => <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password_confirmation')} />}
          </Campo>
          <div className="space-y-1">
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1"
                aria-invalid={Boolean(erros.aceite_termos)}
                aria-describedby={erros.aceite_termos ? 'aceite_termos-erro' : undefined}
                {...form.register('aceite_termos')}
              />
              Li e aceito os termos de uso.
            </label>
            {erros.aceite_termos ? <p id="aceite_termos-erro" className="text-sm text-danger">{erros.aceite_termos.message}</p> : null}
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={() => setEtapa(1)}>Voltar</Button>
            <Button type="submit" className="flex-1" disabled={enviando}>{enviando ? 'Criando conta...' : 'Criar conta'}</Button>
          </div>
        </>
      )}

      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
    </form>
  );
}
