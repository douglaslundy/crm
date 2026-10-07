import { EditarVenda } from '@/features/vendas/components/EditarVenda';

export default async function EditarVendaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <EditarVenda id={id} />;
}
