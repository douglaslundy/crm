import type { ModeloDaNota, StatusNota } from '@/features/notas/types';

export interface NotasDoMes {
  modelo: ModeloDaNota;
  status: StatusNota;
  quantidade: number;
}

export interface FaturamentoDoMes {
  /** 'AAAA-MM' */
  mes: string;
  valor_centavos: number;
  quantidade: number;
}

export interface RejeicaoRecente {
  id: string;
  modelo: ModeloDaNota;
  numero: number | null;
  cstat: string | null;
  motivo: string | null;
  mensagem_erro: string | null;
  status: StatusNota;
  created_at: string;
}

export interface DashboardFiscal {
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO';
  certificado: { status: string; validade: string | null; dias_restantes: number | null };
  mes: { periodo: string; notas: NotasDoMes[]; valor_emitido_centavos: number };
  faturamento_12_meses: FaturamentoDoMes[];
  rejeicoes: { quantidade: number; recentes: RejeicaoRecente[] };
}
