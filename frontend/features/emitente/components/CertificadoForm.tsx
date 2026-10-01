'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { certificadoSchema, type CertificadoFormDados } from '../schemas';
import type { Emitente } from '../types';

const ROTULO_STATUS: Record<Emitente['certificado_status'], string> = {
  PENDENTE: 'Nenhum certificado enviado ainda.',
  VALIDO: 'Certificado válido.',
  VENCIDO: 'Certificado vencido — envie um novo.',
  INVALIDO: 'Certificado inválido.',
};

export function CertificadoForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const [arquivo, setArquivo] = useState<File | null>(null);
  const form = useForm<CertificadoFormDados>({ resolver: zodResolver(certificadoSchema), defaultValues: { senha: '' } });
  const enviar = useMutation({
    mutationFn: (d: CertificadoFormDados) => {
      if (!arquivo) throw new Error('Selecione o arquivo do certificado.');
      return emitenteApi.uploadCertificado(arquivo, d.senha);
    },
    onSuccess: () => {
      toast.success('Certificado enviado.');
      form.reset({ senha: '' });
      setArquivo(null);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível enviar o certificado.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await enviar.mutateAsync(d).catch(() => undefined); }))}>
      <p className="text-sm text-muted-foreground">{ROTULO_STATUS[emitente.certificado_status]}</p>
      <Campo id="certificado_arquivo" label="Arquivo do certificado (.pfx)">
        {(a11y) => <Input {...a11y} type="file" accept=".pfx,.p12" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} />}
      </Campo>
      <Campo id="certificado_senha" label="Senha do certificado" erro={erros.senha?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('senha')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting || !arquivo}>Enviar certificado</Button>
    </form>
  );
}
