'use client';

import type { ReactNode } from 'react';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { AguardandoAtivacao } from './AguardandoAtivacao';
import { FaixaSituacao } from './FaixaSituacao';

/** Aplica ao conteúdo da empresa a regra de exibição por situação (spec F1 §4). */
export function AreaDaEmpresa({ children }: { children: ReactNode }) {
  const { data: usuario } = useUsuario();
  const tenant = usuario?.tenant;

  if (!tenant) return null;
  if (tenant.situacao === 'PENDENTE') return <AguardandoAtivacao />;

  return (
    <div className="space-y-4">
      <FaixaSituacao situacao={tenant.situacao} testeTerminaEm={tenant.teste_termina_em} />
      {children}
    </div>
  );
}
