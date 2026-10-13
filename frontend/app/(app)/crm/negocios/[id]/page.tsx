import { DetalheDoNegocio } from '@/features/crm/components/DetalheDoNegocio';

export default async function NegocioPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <DetalheDoNegocio id={id} />;
}
