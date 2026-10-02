'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api, ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';

interface ResultadoImportacao {
  criados: number;
  atualizados: number;
  erros: { linha: number; motivo: string }[];
}

/** Genérico: funciona para clientes, produtos e serviços — só muda o endpoint. */
export function ImportarCsvForm({ endpoint, queryKey }: { endpoint: string; queryKey: readonly unknown[] }) {
  const queryClient = useQueryClient();
  const [arquivo, setArquivo] = useState<File | null>(null);
  const [resultado, setResultado] = useState<ResultadoImportacao | null>(null);
  const importar = useMutation({
    mutationFn: async () => {
      if (!arquivo) throw new Error('Selecione um arquivo CSV.');
      const corpo = new FormData();
      corpo.append('arquivo', arquivo);
      return api<{ data: ResultadoImportacao }>(endpoint, { method: 'POST', body: corpo });
    },
    onSuccess: ({ data }) => {
      setResultado(data);
      toast.success(`${data.criados} criado(s), ${data.atualizados} atualizado(s).`);
      void queryClient.invalidateQueries({ queryKey });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível importar o arquivo.'),
  });
  const envioUnico = useEnvioUnico();

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(async () => { await importar.mutateAsync().catch(() => undefined); })}>
      <Input type="file" accept=".csv" aria-label="Arquivo CSV" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} />
      <Button type="submit" size="sm" disabled={importar.isPending || !arquivo}>Importar CSV</Button>
      {resultado && resultado.erros.length > 0 ? (
        <ul className="space-y-1 text-sm text-danger">
          {resultado.erros.map((e) => <li key={e.linha}>Linha {e.linha}: {e.motivo}</li>)}
        </ul>
      ) : null}
    </form>
  );
}
