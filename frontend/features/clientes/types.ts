export type TipoCliente = 'PF' | 'PJ';
export type EstagioCliente = 'LEAD' | 'CLIENTE';

export interface Contato {
  id: string;
  nome: string;
  cargo: string | null;
  email: string | null;
  telefone: string | null;
}

export interface Cliente {
  id: string;
  tipo: TipoCliente;
  nome: string;
  cpf_cnpj: string | null;
  inscricao_estadual: string | null;
  ie_isento: boolean;
  email: string | null;
  telefone: string | null;
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  tags: string[];
  origem: string | null;
  estagio: EstagioCliente;
  contatos?: Contato[];
}

export interface ClientePayload {
  tipo: TipoCliente;
  nome: string;
  cpf_cnpj: string | null;
  inscricao_estadual: string | null;
  ie_isento: boolean;
  email: string | null;
  telefone: string | null;
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  origem: string | null;
}
