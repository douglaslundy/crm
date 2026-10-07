'use client';

import { useState } from 'react';
import { Campo } from '@/components/form/Campo';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import type { Nota } from '../types';
import { NotaDeServicoForm } from './NotaDeServicoForm';
import { NotaForm } from './NotaForm';

type Tipo = 'PRODUTOS' | 'SERVICOS';

/** Escolhe o formulário: produtos (NF-e/NFC-e) ou serviços (NFS-e). Nota existente usa o modelo que ela já tem. */
export function FormularioDeNota({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  const assinatura = useAssinatura();
  const temNfse = assinatura.data?.plano?.modulos.includes('FISCAL_NFSE') ?? false;
  const [tipo, setTipo] = useState<Tipo>('PRODUTOS');
  const servico = nota ? nota.modelo === 'DPS' : tipo === 'SERVICOS';

  return (
    <div className="space-y-4">
      {!nota && temNfse ? (
        <div className="max-w-xs">
          <Campo id="nota_tipo" label="Tipo de nota">
            {(a11y) => (
              <SelectNativo {...a11y} value={tipo} onChange={(e) => setTipo(e.target.value as Tipo)}>
                <option value="PRODUTOS">Produtos (NF-e ou NFC-e)</option>
                <option value="SERVICOS">Serviços (NFS-e)</option>
              </SelectNativo>
            )}
          </Campo>
        </div>
      ) : null}
      {servico ? <NotaDeServicoForm nota={nota} onSalvo={onSalvo} /> : <NotaForm nota={nota} onSalvo={onSalvo} />}
    </div>
  );
}
