'use client';

import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { ExigirArea } from '@/components/layout/ExigirArea';
import { NAV_ITEMS_ADMIN } from '@/components/layout/nav-items';

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <AuthGuard>
      <ExigirArea area="plataforma">
        <AppShell itens={NAV_ITEMS_ADMIN}>{children}</AppShell>
      </ExigirArea>
    </AuthGuard>
  );
}
