'use client';

import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { CertificadoForm } from './CertificadoForm';
import { CscForm } from './CscForm';
import { DadosDaEmpresaForm } from './DadosDaEmpresaForm';
import { DadosFiscaisForm } from './DadosFiscaisForm';
import { SeriesForm } from './SeriesForm';

export function EmitenteWizard() {
  const router = useRouter();
  const { data: emitente, isPending } = useQuery({ queryKey: QUERY_KEY_EMITENTE, queryFn: async () => (await emitenteApi.buscar()).data });
  const [passo, setPasso] = useState(0);

  if (isPending || !emitente) return <p className="text-muted-foreground">Carregando...</p>;

  const passos = [
    { titulo: 'Dados da empresa', conteudo: <DadosDaEmpresaForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    { titulo: 'Dados fiscais', conteudo: <DadosFiscaisForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    { titulo: 'Certificado A1', conteudo: <CertificadoForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    ...(emitente.exige_csc ? [{ titulo: 'CSC', conteudo: <CscForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> }] : []),
    { titulo: 'Séries', conteudo: <SeriesForm emitente={emitente} modelo="NFE" rotulo="NF-e" onSalvar={() => router.push('/dashboard')} /> },
  ];
  const atual = passos[passo] ?? passos[passos.length - 1];

  return (
    <div className="mx-auto max-w-md space-y-4">
      <p className="text-sm text-muted-foreground">Passo {passo + 1} de {passos.length}</p>
      <h1 className="text-xl font-semibold">{atual.titulo}</h1>
      {atual.conteudo}
      <button type="button" className="text-sm text-muted-foreground underline" onClick={() => router.push('/dashboard')}>
        Continuar depois (fica em Configurações)
      </button>
    </div>
  );
}
