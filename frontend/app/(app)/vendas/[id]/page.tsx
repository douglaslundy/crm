import { DetalheDaVenda } from '@/features/vendas/components/DetalheDaVenda';

export default async function VendaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <DetalheDaVenda id={id} />;
}
