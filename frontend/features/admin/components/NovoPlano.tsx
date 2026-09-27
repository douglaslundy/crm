'use client';

import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { toast } from 'sonner';
import { adminApi } from '../api';
import { PlanoForm } from './PlanoForm';

export function NovoPlano() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Novo plano</h1>
      <PlanoForm
        rotuloBotao="Criar plano"
        onSalvar={async (entrada) => {
          const { data } = await adminApi.criarPlano(entrada);
          void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'] });
          toast.success('Plano criado.');
          router.push(`/admin/planos/${data.id}`);
        }}
      />
    </div>
  );
}
