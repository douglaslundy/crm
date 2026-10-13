import type { Metadata } from 'next';
import { PaginaPublicaDaProposta } from '@/features/crm/components/PaginaPublicaDaProposta';

// Link privado: não indexar.
export const metadata: Metadata = { title: 'Proposta', robots: { index: false, follow: false } };

export default async function PropostaPublicaPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return <PaginaPublicaDaProposta token={token} />;
}
