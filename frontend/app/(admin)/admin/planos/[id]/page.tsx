import { EditarPlano } from '@/features/admin/components/EditarPlano';

export default async function EditarPlanoPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <EditarPlano id={id} />;
}
