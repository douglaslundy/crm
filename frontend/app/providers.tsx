'use client';

import { QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from 'next-themes';
import { useState, type ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';

function criarQueryClient(): QueryClient {
  return new QueryClient({
    queryCache: new QueryCache({
      onError: (erro) => {
        const status = (erro as { status?: number }).status;
        if (status === 401 && !window.location.pathname.startsWith('/login')) {
          // Fora do React não há router; o reload completo também descarta o estado da sessão expirada.
          // eslint-disable-next-line @next/next/no-location-assign-relative-destination
          window.location.assign('/login');
        }
      },
    }),
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
