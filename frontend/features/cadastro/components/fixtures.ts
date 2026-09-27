import type { Plano } from '@/features/assinatura/types';

export const planoDeTeste: Plano = {
  id: 'p1', nome: 'Essencial', descricao: 'Para começar.', preco_mensal_centavos: 9990, preco_anual_centavos: null,
  dias_teste: 14, politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null,
  modulos: ['FISCAL_NFE'],
  limites: { USUARIOS: 3, CLIENTES: -1, PRODUTOS: 100, SERVICOS: 0, DOCUMENTOS_MES: 200, API_REQUISICOES_MIN: 0 },
  ativo: true, visivel: true, ordem: 1,
};
