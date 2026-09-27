import { PainelMetricas } from '@/features/admin/components/PainelMetricas';

export default function PainelPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Painel</h1>
      <PainelMetricas />
    </div>
  );
}
