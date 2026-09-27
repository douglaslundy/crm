import { ListaDePlanos } from '@/features/cadastro/components/ListaDePlanos';

export default function PlanosPage() {
  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold">Escolha seu plano</h1>
        <p className="text-muted-foreground">Emita NF-e, NFC-e e NFS-e e organize seus clientes em um só lugar.</p>
      </div>
      <ListaDePlanos />
    </div>
  );
}
