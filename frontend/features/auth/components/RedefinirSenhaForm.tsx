'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { authApi } from '../api';
import { redefinirSenhaSchema, type RedefinirSenhaDados } from '../schemas';
import { Banner } from '@/components/ui/banner';

type Modo = 'redefinir' | 'convite';

const MENSAGEM_LINK_INVALIDO: Record<Modo, string> = {
  redefinir: 'Link inválido ou expirado.',
  convite: 'Convite inválido ou expirado. Use "Esqueci minha senha" na tela de entrada para definir sua senha.',
};

const ROTULO_LINK: Record<Modo, string> = {
  redefinir: 'Solicitar novo link',
  convite: 'Esqueci minha senha',
};

export function RedefinirSenhaForm({
  token,
  email,
  modo = 'redefinir',
}: {
  token: string;
  email: string;
  modo?: Modo;
}) {
  const router = useRouter();
  const form = useForm<RedefinirSenhaDados>({
    resolver: zodResolver(redefinirSenhaSchema),
    defaultValues: { token, email, password: '', password_confirmation: '' },
  });
  const redefinir = useMutation({
    mutationFn: (dados: RedefinirSenhaDados) => (modo === 'convite' ? authApi.aceitarConvite(dados) : authApi.redefinirSenha(dados)),
    onSuccess: ({ message }) => {
      toast.success(message);
      router.replace('/login');
    },
    onError: (erro) =>
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });

  const envioUnico = useEnvioUnico();

  if (!token || !email) {
    return (
      <div className="space-y-4 text-center">
        <Banner tom="perigo">{MENSAGEM_LINK_INVALIDO[modo]}</Banner>
        <Link href="/esqueci-senha" className="text-sm underline">{ROTULO_LINK[modo]}</Link>
      </div>
    );
  }

  const enviando = form.formState.isSubmitting;
  const erros = form.formState.errors;
  return (
    <form
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await redefinir.mutateAsync(d).catch(() => undefined); }))}
      noValidate
      className="space-y-4"
    >
      <Campo id="password" label="Nova senha" erro={erros.password?.message}>
        {(a11y) => <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password')} />}
      </Campo>
      <Campo id="password_confirmation" label="Confirmar nova senha" erro={erros.password_confirmation?.message}>
        {(a11y) => (
          <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password_confirmation')} />
        )}
      </Campo>
      {erros.token || erros.email ? (
        <Banner tom="perigo">
          {MENSAGEM_LINK_INVALIDO[modo]} <Link href="/esqueci-senha" className="underline">{ROTULO_LINK[modo]}</Link>
        </Banner>
      ) : null}
      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" className="w-full" disabled={enviando}>
        {enviando ? 'Salvando...' : modo === 'convite' ? 'Definir senha' : 'Redefinir senha'}
      </Button>
    </form>
  );
}
