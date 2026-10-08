import type { Metadata } from 'next';
import { DocumentacaoDaApi } from '@/features/docs-api/DocumentacaoDaApi';

export const metadata: Metadata = {
  title: 'API — Plataforma Fiscal',
  description: 'Referência OpenAPI 3.1 da API pública.',
};

export default function DocumentacaoDaApiPage() {
  return <DocumentacaoDaApi />;
}
