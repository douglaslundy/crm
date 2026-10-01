export interface Servico {
  id: string;
  nome: string;
  preco_centavos: number;
  codigo_lc116: string | null;
  c_trib_nac: string | null;
  codigo_municipal: string | null;
  aliquota_iss: string | null;
  nbs: string | null;
  pendente_fiscal: boolean;
}

export interface ServicoPayload {
  nome: string;
  preco_centavos: number;
  codigo_lc116: string | null;
  c_trib_nac: string | null;
  codigo_municipal: string | null;
  aliquota_iss: string | null;
  nbs: string | null;
}
