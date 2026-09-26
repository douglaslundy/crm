'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { authApi } from '../api';
import { redefinirSenhaSchema, type RedefinirSenhaDados } from '../schemas';

export function RedefinirSenhaForm({ token, email }: { token: string; email: string }) {
  const router = useRouter();
  const form = useForm<RedefinirSenhaDados>({
    resolver: zodResolver(redefinirSenhaSchema),
    defaultValues: { token, email, password: '', password_confirmation: '' },
  });
  const redefinir = useMutation({
    mutationFn: (dados: RedefinirSenhaDados) => authApi.redefinirSenha(dados),
    onSuccess: ({ message }) => {
      toast.success(message);
      router.replace('/login');
    },
    onError: (erro) =>
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });

  if (!token || !email) {
    return (
      <div className="space-y-4 text-center">
        <p role="alert" className="text-danger">Link inválido ou expirado.</p>
        <Link href="/esqueci-senha" className="text-sm underline">Solicitar novo link</Link>
      </div>
    );
  }

  const erros = form.formState.errors;
  return (
    <form onSubmit={form.handleSubmit((d) => redefinir.mutate(d))} noValidate className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="password">Nova senha</Label>
        <Input id="password" type="password" autoComplete="new-password" {...form.register('password')} />
        {erros.password ? <p className="text-sm text-danger">{erros.password.message}</p> : null}
      </div>
      <div className="space-y-2">
        <Label htmlFor="password_confirmation">Confirmar nova senha</Label>
        <Input id="password_confirmation" type="password" autoComplete="new-password" {...form.register('password_confirmation')} />
        {erros.password_confirmation ? (
          <p className="text-sm text-danger">{erros.password_confirmation.message}</p>
        ) : null}
      </div>
      {erros.root ? <p role="alert" className="text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={redefinir.isPending}>
        {redefinir.isPending ? 'Salvando...' : 'Redefinir senha'}
      </Button>
    </form>
  );
}
