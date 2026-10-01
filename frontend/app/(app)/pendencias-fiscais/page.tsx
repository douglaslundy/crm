'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { PendenciasFiscais } from '@/features/pendencias-fiscais/components/PendenciasFiscais';

const PAPEIS_QUE_REVISAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];

export default function PendenciasFiscaisPage() {
  const { data: usuario } = useUsuario();

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Pendências fiscais</h1>
      <Card>
        <CardHeader><CardTitle>Produtos e serviços sem os dados obrigatórios</CardTitle></CardHeader>
        <CardContent>
          <PendenciasFiscais podeRevisar={Boolean(usuario && PAPEIS_QUE_REVISAM.includes(usuario.papel))} />
        </CardContent>
      </Card>
    </div>
  );
}
