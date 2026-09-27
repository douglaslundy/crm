import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { ExigirArea } from '@/components/layout/ExigirArea';
import { AreaDaEmpresa } from '@/features/assinatura/components/AreaDaEmpresa';

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <AuthGuard>
      <ExigirArea area="empresa">
        <AppShell>
          <AreaDaEmpresa>{children}</AreaDaEmpresa>
        </AppShell>
      </ExigirArea>
    </AuthGuard>
  );
}
