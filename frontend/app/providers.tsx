'use client';

import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from 'next-themes';
import { useState, type ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { irParaLoginSeNaoAutenticado } from '@/lib/sessao';

function criarQueryClient(): QueryClient {
  const aoErrar = (erro: unknown) => irParaLoginSeNaoAutenticado(erro);

  return new QueryClient({
    queryCache: new QueryCache({ onError: aoErrar }),
    mutationCache: new MutationCache({ onError: aoErrar }),
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(criarQueryClient);

  return (
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
      <QueryClientProvider client={queryClient}>
        {children}
        <Toaster richColors position="top-right" />
      </QueryClientProvider>
    </ThemeProvider>
  );
}
