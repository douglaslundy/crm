import { AuthCard } from '@/features/auth/components/AuthCard';
import { RedefinirSenhaForm } from '@/features/auth/components/RedefinirSenhaForm';

export default async function DefinirSenhaPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token = '', email = '' } = await searchParams;

  return (
    <AuthCard titulo="Defina sua senha" descricao="Você foi convidado. Crie sua senha para entrar.">
      <RedefinirSenhaForm token={token} email={email} modo="convite" />
    </AuthCard>
  );
}
