import { AuthCard } from '@/features/auth/components/AuthCard';
import { RedefinirSenhaForm } from '@/features/auth/components/RedefinirSenhaForm';

export default async function RedefinirSenhaPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token = '', email = '' } = await searchParams;

  return (
    <AuthCard titulo="Redefinir senha">
      <RedefinirSenhaForm token={token} email={email} />
    </AuthCard>
  );
}
