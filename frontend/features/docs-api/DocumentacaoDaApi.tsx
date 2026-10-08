'use client';

import dynamic from 'next/dynamic';

// O Redoc é pesado e só roda no navegador: carrega sob demanda, fora do bundle das telas do app.
const Redoc = dynamic(() => import('redoc').then((modulo) => modulo.RedocStandalone), {
  ssr: false,
  loading: () => <p className="p-4 text-muted-foreground">Carregando documentação…</p>,
});

const ESPECIFICACAO = '/openapi.yaml';

export function DocumentacaoDaApi() {
  return (
    <div className="min-h-dvh">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-surface px-4 py-3">
        <h1 className="font-display text-lg font-extrabold">API da Plataforma Fiscal</h1>
        <a href={ESPECIFICACAO} download className="text-sm text-primary underline-offset-4 hover:underline">
          Baixar OpenAPI
        </a>
      </header>
      <Redoc specUrl={ESPECIFICACAO} options={{ hideDownloadButton: true, nativeScrollbars: true }} />
    </div>
  );
}
