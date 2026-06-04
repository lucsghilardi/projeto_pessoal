"use client";

import { useEffect, useMemo, useState } from "react";
import { ShieldUser, UserPlus, Users } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { useAuth } from "@/context/AuthContext";
import { appToast } from "@/lib/toast";
import { createUser, getUsers, updateUser } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  CreateUserPayload,
  UpdateUserPayload,
  User,
  UserRole,
} from "@/types/User";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const ROLE_LABELS: Record<UserRole, string> = {
  admin: "Admin",
  editor: "Editor",
  viewer: "Viewer",
};

const ROLE_DESCRIPTIONS: Record<UserRole, string> = {
  admin: "Acesso completo ao painel e gerenciamento de usuarios.",
  editor: "Acesso operacional para manutencao de conteudo.",
  viewer: "Acesso restrito para consulta do painel.",
};

const ROLE_ORDER: Record<UserRole, number> = {
  admin: 0,
  editor: 1,
  viewer: 2,
};

const emptyCreateForm: CreateUserPayload = {
  name: "",
  email: "",
  role: "editor",
  password: "",
  password_confirmation: "",
};

const emptyEditForm: UpdateUserPayload = {
  name: "",
  email: "",
  role: "editor",
  is_active: true,
  password: "",
  password_confirmation: "",
};

function sortUsers(users: User[]) {
  return [...users].sort((left, right) => {
    const activeDiff = Number(right.is_active) - Number(left.is_active);

    if (activeDiff !== 0) {
      return activeDiff;
    }

    const roleDiff = ROLE_ORDER[left.role] - ROLE_ORDER[right.role];

    if (roleDiff !== 0) {
      return roleDiff;
    }

    return left.name.localeCompare(right.name, "pt-BR", {
      sensitivity: "base",
    });
  });
}

function formatDateTime(value?: string) {
  if (!value) {
    return "-";
  }

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(value));
}

function getRolePillClassName(role: UserRole) {
  if (role === "admin") {
    return "border-red-200 bg-red-50 text-red-700";
  }

  if (role === "editor") {
    return "border-amber-200 bg-amber-50 text-amber-700";
  }

  return "border-zinc-200 bg-zinc-100 text-zinc-700";
}

function getStatusPillClassName(isActive: boolean) {
  return isActive
    ? "border-emerald-200 bg-emerald-50 text-emerald-700"
    : "border-zinc-200 bg-zinc-100 text-zinc-700";
}

function mapUserToEditForm(user: User): UpdateUserPayload {
  return {
    name: user.name,
    email: user.email,
    role: user.role,
    is_active: user.is_active,
    password: "",
    password_confirmation: "",
  };
}

export default function UsersPage() {
  const { user: authenticatedUser } = useAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [savingEdit, setSavingEdit] = useState(false);
  const [createForm, setCreateForm] = useState<CreateUserPayload>(emptyCreateForm);
  const [editForm, setEditForm] = useState<UpdateUserPayload>(emptyEditForm);
  const [createFormError, setCreateFormError] = useState<string | null>(null);
  const [editFormError, setEditFormError] = useState<string | null>(null);
  const [isEditSheetOpen, setIsEditSheetOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  useEffect(() => {
    let mounted = true;

    async function loadUsers() {
      try {
        const data = await getUsers();

        if (mounted) {
          setUsers(sortUsers(data));
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Nao foi possivel carregar os usuarios."
        );
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    }

    loadUsers();

    return () => {
      mounted = false;
    };
  }, []);

  const activeAdminCount = useMemo(
    () =>
      users.filter(
        (currentUser) => currentUser.role === "admin" && currentUser.is_active,
      ).length,
    [users],
  );

  const userStats = useMemo(
    () => ({
      total: users.length,
      admins: users.filter((currentUser) => currentUser.role === "admin").length,
      editors: users.filter((currentUser) => currentUser.role === "editor").length,
      viewers: users.filter((currentUser) => currentUser.role === "viewer").length,
      inactive: users.filter((currentUser) => !currentUser.is_active).length,
    }),
    [users],
  );

  const selectedUserRules = useMemo(() => {
    if (!selectedUser) {
      return {
        isCurrentUser: false,
        isLastActiveAdmin: false,
      };
    }

    return {
      isCurrentUser: authenticatedUser?.id === selectedUser.id,
      isLastActiveAdmin:
        selectedUser.role === "admin" &&
        selectedUser.is_active &&
        activeAdminCount <= 1,
    };
  }, [activeAdminCount, authenticatedUser?.id, selectedUser]);

  function handleEditSheetChange(open: boolean) {
    setIsEditSheetOpen(open);

    if (!open) {
      setSelectedUser(null);
      setEditForm(emptyEditForm);
      setEditFormError(null);
    }
  }

  function openEditSheet(user: User) {
    setSelectedUser(user);
    setEditForm(mapUserToEditForm(user));
    setEditFormError(null);
    setIsEditSheetOpen(true);
  }

  async function handleCreateUser(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setCreating(true);
    setCreateFormError(null);

    try {
      const createdUser = await createUser({
        name: createForm.name.trim(),
        email: createForm.email.trim().toLowerCase(),
        role: createForm.role,
        password: createForm.password,
        password_confirmation: createForm.password_confirmation,
      });

      setUsers((currentUsers) => sortUsers([createdUser, ...currentUsers]));
      setCreateForm(emptyCreateForm);
      appToast.success("Usuario criado com sucesso.");
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Nao foi possivel criar o usuario.";

      setCreateFormError(message);
      appToast.error(message);
    } finally {
      setCreating(false);
    }
  }

  async function handleEditUser(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!selectedUser) {
      return;
    }

    setSavingEdit(true);
    setEditFormError(null);

    try {
      const payload: UpdateUserPayload = {
        name: editForm.name.trim(),
        email: editForm.email.trim().toLowerCase(),
        role: editForm.role,
        is_active: editForm.is_active,
      };

      if (editForm.password) {
        payload.password = editForm.password;
        payload.password_confirmation = editForm.password_confirmation;
      }

      const updatedUser = await updateUser(selectedUser.id, payload);

      setUsers((currentUsers) =>
        sortUsers(
          currentUsers.map((currentUser) =>
            currentUser.id === updatedUser.id ? updatedUser : currentUser,
          ),
        ),
      );

      handleEditSheetChange(false);
      appToast.success("Usuario atualizado com sucesso.");

      if (authenticatedUser?.id === updatedUser.id) {
        window.location.reload();
      }
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Nao foi possivel atualizar o usuario.";

      setEditFormError(message);
      appToast.error(message);
    } finally {
      setSavingEdit(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando acessos do painel..." />;
  }

  return (
    <>
      <div className="space-y-6">
        <DashboardPageHeader
          title="Acessos do painel"
          description="Crie usuarios internos, edite perfis, desative acessos indevidos e redefina senha sem depender de cadastro publico."
          actions={
            <div className="inline-flex items-center gap-2 rounded-full border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
              <ShieldUser className="size-4" />
              Criacao e edicao restritas a administradores
            </div>
          }
        />

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <Card className="border-red-100 bg-red-50/60">
            <CardContent className="flex items-center justify-between pt-6">
              <div>
                <p className="text-sm text-muted-foreground">Total de acessos</p>
                <p className="text-3xl font-semibold">{userStats.total}</p>
              </div>
              <Users className="size-5 text-red-700" />
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">Admins</p>
              <p className="text-3xl font-semibold">{userStats.admins}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">Editors</p>
              <p className="text-3xl font-semibold">{userStats.editors}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">Viewers</p>
              <p className="text-3xl font-semibold">{userStats.viewers}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-sm text-muted-foreground">Desativados</p>
              <p className="text-3xl font-semibold">{userStats.inactive}</p>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
          <Card className="border-zinc-200">
            <CardHeader>
              <CardTitle>Novo usuario</CardTitle>
              <CardDescription>
                Defina credenciais internas para o painel. O email sera usado no
                login administrativo.
              </CardDescription>
            </CardHeader>

            <CardContent>
              <form onSubmit={handleCreateUser}>
                <FieldGroup className="gap-5">
                  <Field>
                    <FieldLabel htmlFor="name">Nome</FieldLabel>
                    <Input
                      id="name"
                      value={createForm.name}
                      onChange={(event) =>
                        setCreateForm((current) => ({
                          ...current,
                          name: event.target.value,
                        }))
                      }
                      placeholder="Ex.: Equipe Marketing"
                      disabled={creating}
                      required
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="email">Email</FieldLabel>
                    <Input
                      id="email"
                      type="email"
                      autoComplete="off"
                      value={createForm.email}
                      onChange={(event) =>
                        setCreateForm((current) => ({
                          ...current,
                          email: event.target.value,
                        }))
                      }
                      placeholder="usuario@teste.nitrogymacademia.com.br"
                      disabled={creating}
                      required
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="role">Perfil</FieldLabel>
                    <Select
                      value={createForm.role}
                      onValueChange={(value) =>
                        setCreateForm((current) => ({
                          ...current,
                          role: value as UserRole,
                        }))
                      }
                      disabled={creating}
                    >
                      <SelectTrigger id="role" className="w-full">
                        <SelectValue placeholder="Selecione o perfil" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="admin">Admin</SelectItem>
                        <SelectItem value="editor">Editor</SelectItem>
                        <SelectItem value="viewer">Viewer</SelectItem>
                      </SelectContent>
                    </Select>
                    <FieldDescription>
                      {ROLE_DESCRIPTIONS[createForm.role]}
                    </FieldDescription>
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="password">Senha</FieldLabel>
                    <Input
                      id="password"
                      type="password"
                      value={createForm.password}
                      onChange={(event) =>
                        setCreateForm((current) => ({
                          ...current,
                          password: event.target.value,
                        }))
                      }
                      disabled={creating}
                      required
                    />
                    <FieldDescription>
                      Use pelo menos 8 caracteres com letras e numeros.
                    </FieldDescription>
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="password_confirmation">
                      Confirmar senha
                    </FieldLabel>
                    <Input
                      id="password_confirmation"
                      type="password"
                      value={createForm.password_confirmation}
                      onChange={(event) =>
                        setCreateForm((current) => ({
                          ...current,
                          password_confirmation: event.target.value,
                        }))
                      }
                      disabled={creating}
                      required
                    />
                  </Field>

                  <FieldError>{createFormError}</FieldError>

                  <Button type="submit" className="w-full" disabled={creating}>
                    {creating ? (
                      <>
                        <Spinner data-icon="inline-start" />
                        Criando usuario...
                      </>
                    ) : (
                      <>
                        <UserPlus className="size-4" />
                        Criar acesso
                      </>
                    )}
                  </Button>
                </FieldGroup>
              </form>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Usuarios cadastrados</CardTitle>
              <CardDescription>
                O admin pode editar nome, email, perfil, status e definir uma nova
                senha. O proprio usuario logado e o ultimo admin ativo continuam
                protegidos.
              </CardDescription>
            </CardHeader>

            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="min-w-[20rem]">Usuario</TableHead>
                      <TableHead className="min-w-[10rem]">Perfil</TableHead>
                      <TableHead className="min-w-[10rem]">Status</TableHead>
                      <TableHead className="min-w-[10rem]">Criado em</TableHead>
                      <TableHead className="min-w-[10rem] text-right">
                        Acoes
                      </TableHead>
                    </TableRow>
                  </TableHeader>

                  <TableBody>
                    {users.length === 0 ? (
                      <TableRow>
                        <TableCell
                          colSpan={5}
                          className="py-10 text-center text-muted-foreground"
                        >
                          Nenhum usuario administrativo cadastrado.
                        </TableCell>
                      </TableRow>
                    ) : (
                      users.map((user) => {
                        const isCurrentUser = authenticatedUser?.id === user.id;
                        const isLastActiveAdmin =
                          user.role === "admin" &&
                          user.is_active &&
                          activeAdminCount <= 1;

                        return (
                          <TableRow key={user.id}>
                            <TableCell className="align-top">
                              <div className="space-y-1">
                                <div className="font-medium break-words">
                                  {user.name}
                                </div>
                                <div className="text-sm text-muted-foreground break-all">
                                  {user.email}
                                </div>
                                {isCurrentUser ? (
                                  <p className="text-xs text-muted-foreground">
                                    Usuario logado no momento.
                                  </p>
                                ) : null}
                                {!isCurrentUser && isLastActiveAdmin ? (
                                  <p className="text-xs text-muted-foreground">
                                    Ultimo admin ativo protegido.
                                  </p>
                                ) : null}
                              </div>
                            </TableCell>
                            <TableCell className="align-top">
                              <span
                                className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ${getRolePillClassName(user.role)}`}
                              >
                                {ROLE_LABELS[user.role]}
                              </span>
                            </TableCell>
                            <TableCell className="align-top">
                              <span
                                className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ${getStatusPillClassName(user.is_active)}`}
                              >
                                {user.is_active ? "Ativo" : "Desativado"}
                              </span>
                            </TableCell>
                            <TableCell className="align-top">
                              {formatDateTime(user.created_at)}
                            </TableCell>
                            <TableCell className="align-top text-right">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => openEditSheet(user)}
                              >
                                Editar acesso
                              </Button>
                            </TableCell>
                          </TableRow>
                        );
                      })
                    )}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      <Sheet open={isEditSheetOpen} onOpenChange={handleEditSheetChange}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
          <form onSubmit={handleEditUser} className="flex h-full flex-col">
            <SheetHeader className="border-b">
              <SheetTitle>Editar usuario</SheetTitle>
              <SheetDescription>
                Ajuste o acesso do painel, desative login quando necessario e use a
                senha abaixo apenas se quiser redefinir a credencial.
              </SheetDescription>
            </SheetHeader>

            <div className="flex-1 space-y-6 px-4 py-5">
              <FieldGroup className="gap-5">
                <Field>
                  <FieldLabel htmlFor="edit-name">Nome</FieldLabel>
                  <Input
                    id="edit-name"
                    value={editForm.name}
                    onChange={(event) =>
                      setEditForm((current) => ({
                        ...current,
                        name: event.target.value,
                      }))
                    }
                    disabled={savingEdit}
                    required
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="edit-email">Email</FieldLabel>
                  <Input
                    id="edit-email"
                    type="email"
                    value={editForm.email}
                    onChange={(event) =>
                      setEditForm((current) => ({
                        ...current,
                        email: event.target.value,
                      }))
                    }
                    disabled={savingEdit}
                    required
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="edit-role">Perfil</FieldLabel>
                  <Select
                    value={editForm.role}
                    onValueChange={(value) =>
                      setEditForm((current) => ({
                        ...current,
                        role: value as UserRole,
                      }))
                    }
                    disabled={
                      savingEdit ||
                      selectedUserRules.isCurrentUser ||
                      selectedUserRules.isLastActiveAdmin
                    }
                  >
                    <SelectTrigger id="edit-role" className="w-full">
                      <SelectValue placeholder="Selecione o perfil" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="admin">Admin</SelectItem>
                      <SelectItem value="editor">Editor</SelectItem>
                      <SelectItem value="viewer">Viewer</SelectItem>
                    </SelectContent>
                  </Select>
                  <FieldDescription>
                    {selectedUserRules.isCurrentUser
                      ? "Seu proprio perfil continua bloqueado por seguranca."
                      : selectedUserRules.isLastActiveAdmin
                        ? "O ultimo admin ativo nao pode perder o perfil admin."
                      : ROLE_DESCRIPTIONS[editForm.role]}
                  </FieldDescription>
                </Field>

                <Field>
                  <FieldLabel htmlFor="edit-status">Status do acesso</FieldLabel>
                  <label
                    htmlFor="edit-status"
                    className="flex items-center gap-3 rounded-md border px-3 py-3 text-sm"
                  >
                    <input
                      id="edit-status"
                      type="checkbox"
                      checked={editForm.is_active}
                      onChange={(event) =>
                        setEditForm((current) => ({
                          ...current,
                          is_active: event.target.checked,
                        }))
                      }
                      disabled={
                        savingEdit ||
                        selectedUserRules.isCurrentUser ||
                        selectedUserRules.isLastActiveAdmin
                      }
                      className="size-4 rounded border-input"
                    />
                    Usuario ativo e liberado para login
                  </label>
                  <FieldDescription>
                    {selectedUserRules.isCurrentUser
                      ? "Seu proprio acesso nao pode ser desativado por esta tela."
                      : selectedUserRules.isLastActiveAdmin
                        ? "O ultimo admin ativo nao pode ser desativado."
                        : "Ao desativar, o usuario perde o acesso ao painel no proximo login ou reload."}
                  </FieldDescription>
                </Field>

                <Field>
                  <FieldLabel htmlFor="edit-password">Nova senha</FieldLabel>
                  <Input
                    id="edit-password"
                    type="password"
                    value={editForm.password ?? ""}
                    onChange={(event) =>
                      setEditForm((current) => ({
                        ...current,
                        password: event.target.value,
                      }))
                    }
                    disabled={savingEdit}
                    placeholder="Preencha apenas para redefinir"
                  />
                  <FieldDescription>
                    Se deixar em branco, a senha atual sera mantida.
                  </FieldDescription>
                </Field>

                <Field>
                  <FieldLabel htmlFor="edit-password-confirmation">
                    Confirmar nova senha
                  </FieldLabel>
                  <Input
                    id="edit-password-confirmation"
                    type="password"
                    value={editForm.password_confirmation ?? ""}
                    onChange={(event) =>
                      setEditForm((current) => ({
                        ...current,
                        password_confirmation: event.target.value,
                      }))
                    }
                    disabled={savingEdit}
                    placeholder="Repita a nova senha"
                  />
                </Field>

                <FieldError>{editFormError}</FieldError>
              </FieldGroup>
            </div>

            <SheetFooter className="border-t">
              <Button
                type="button"
                variant="outline"
                onClick={() => handleEditSheetChange(false)}
                disabled={savingEdit}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={savingEdit || !selectedUser}>
                {savingEdit ? (
                  <>
                    <Spinner data-icon="inline-start" />
                    Salvando...
                  </>
                ) : (
                  "Salvar alteracoes"
                )}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </>
  );
}
