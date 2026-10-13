'use client';

import { Mail, MessageCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useContato } from '../hooks/useAtividades';

interface Props {
  clienteId?: string;
  negocioId?: string;
  podeEscrever: boolean;
}

export function BotoesDeContato({ clienteId, negocioId, podeEscrever }: Props) {
  const contato = useContato();
  if (!podeEscrever) return null;

  return (
    <div className="flex gap-2">
      <Button
        variant="outline"
        size="sm"
        disabled={contato.isPending}
        onClick={() => contato.mutate({ canal: 'WHATSAPP', clienteId, negocioId })}
      >
        <MessageCircle aria-hidden /> WhatsApp
      </Button>
      <Button
        variant="outline"
        size="sm"
        disabled={contato.isPending}
        onClick={() => contato.mutate({ canal: 'EMAIL', clienteId, negocioId })}
      >
        <Mail aria-hidden /> E-mail
      </Button>
    </div>
  );
}
