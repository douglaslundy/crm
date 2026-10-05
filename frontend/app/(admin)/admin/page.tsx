import { PainelMetricas } from '@/features/admin/components/PainelMetricas';

export default function PainelPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Painel</h1>
      <PainelMetricas />
    </div>
  );
}
