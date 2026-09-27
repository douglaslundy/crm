import Link from 'next/link';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CadastroForm } from '@/features/cadastro/components/CadastroForm';

export default async function CadastroPage({ searchParams }: { searchParams: Promise<{ plano?: string }> }) {
  const { plano } = await searchParams;

  return (
    <Card className="mx-auto max-w-md">
      <CardHeader>
        <CardTitle><h1>Criar conta</h1></CardTitle>
      </CardHeader>
      <CardContent>
        {plano ? (
          <CadastroForm planoId={plano} />
        ) : (
          <p>Escolha um plano para começar. <Link href="/planos" className="underline">Ver planos</Link></p>
        )}
      </CardContent>
    </Card>
  );
}
