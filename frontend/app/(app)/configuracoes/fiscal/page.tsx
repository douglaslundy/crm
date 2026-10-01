'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { emitenteApi, QUERY_KEY_EMITENTE } from '@/features/emitente/api';
import { CertificadoForm } from '@/features/emitente/components/CertificadoForm';
import { CscForm } from '@/features/emitente/components/CscForm';
import { DadosDaEmpresaForm } from '@/features/emitente/components/DadosDaEmpresaForm';
import { DadosFiscaisForm } from '@/features/emitente/components/DadosFiscaisForm';
import { SeriesForm } from '@/features/emitente/components/SeriesForm';

export default function ConfiguracoesFiscalPage() {
  const { data: emitente, isPending, isError } = useQuery({ queryKey: QUERY_KEY_EMITENTE, queryFn: async () => (await emitenteApi.buscar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError || !emitente) return <p role="alert">Não foi possível carregar os dados fiscais.</p>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Configurações fiscais</h1>
      <div className="grid gap-4 md:grid-cols-2">
        <Card><CardHeader><CardTitle>Empresa</CardTitle></CardHeader><CardContent><DadosDaEmpresaForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Dados fiscais</CardTitle></CardHeader><CardContent><DadosFiscaisForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Certificado A1</CardTitle></CardHeader><CardContent><CertificadoForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>CSC (NFC-e)</CardTitle></CardHeader><CardContent><CscForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NF-e</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="NFE" rotulo="NF-e" /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NFC-e</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="NFCE" rotulo="NFC-e" /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NFS-e (DPS)</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="DPS" rotulo="NFS-e" /></CardContent></Card>
      </div>
    </div>
  );
}
