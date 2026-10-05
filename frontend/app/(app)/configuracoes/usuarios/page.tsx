'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ConviteForm } from '@/features/usuarios/components/ConviteForm';
import { ListaDeUsuarios } from '@/features/usuarios/components/ListaDeUsuarios';
import { podeGerenciar } from '@/features/usuarios/types';
import { Banner } from '@/components/ui/banner';

export default function UsuariosPage() {
  const { data: usuario } = useUsuario();

  if (!usuario) return null;
  if (!podeGerenciar(usuario.papel)) {
    return <Banner tom="perigo">Você não tem permissão para gerenciar usuários.</Banner>;
  }

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Usuários</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Equipe</CardTitle></CardHeader>
          <CardContent><ListaDeUsuarios autor={usuario} /></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Convidar usuário</CardTitle></CardHeader>
          <CardContent><ConviteForm autor={usuario.papel} /></CardContent>
        </Card>
      </div>
    </div>
  );
}
