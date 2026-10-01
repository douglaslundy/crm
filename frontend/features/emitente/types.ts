export type AmbienteFiscal = 'HOMOLOGACAO' | 'PRODUCAO';
export type CertificadoStatus = 'PENDENTE' | 'VALIDO' | 'VENCIDO' | 'INVALIDO';
export type ModeloDocumento = 'NFE' | 'NFCE' | 'DPS';

export interface EmitenteSerie {
  modelo: ModeloDocumento;
  serie: string;
  proximo_numero: number;
}

export interface Emitente {
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  regime_tributario: string | null;
  inscricao_estadual: string | null;
  inscricao_municipal: string | null;
  cnae: string | null;
  ambiente_fiscal: AmbienteFiscal;
  certificado_status: CertificadoStatus;
  certificado_validade: string | null;
  certificado_titular: string | null;
  csc_homologacao_configurado: boolean;
  csc_producao_configurado: boolean;
  exige_csc: boolean;
  series: EmitenteSerie[];
}
