import { PaginaDoClienteNoCrm } from '@/features/crm/components/PaginaDoClienteNoCrm';

export default async function ClienteNoCrmPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <PaginaDoClienteNoCrm id={id} />;
}
