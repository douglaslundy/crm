import { DetalheDaNota } from '@/features/notas/components/DetalheDaNota';

export default async function NotaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <DetalheDaNota id={id} />;
}
