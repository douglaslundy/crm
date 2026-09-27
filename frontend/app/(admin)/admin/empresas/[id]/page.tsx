import { DetalheDaEmpresa } from '@/features/admin/components/DetalheDaEmpresa';

export default async function EmpresaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <DetalheDaEmpresa id={id} />;
}
