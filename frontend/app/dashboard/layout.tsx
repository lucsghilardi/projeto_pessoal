"use client";

import { AuthProvider } from "@/context/AuthContext";
import { canAccessDashboardRoute, getDashboardFallbackRoute } from "@/lib/dashboard-access";
import { AppSidebar } from "@/components/app-sidebar"
import { Spinner } from "@/components/ui/spinner";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb"
import { Separator } from "@/components/ui/separator"
import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from "@/components/ui/sidebar"

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { breadcrumbMap } from "@/lib/breadcrumbs";
import { useAuth } from "@/context/AuthContext";

function DashboardShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, loading } = useAuth();
  const segments = pathname
    .split("/")
    .filter(Boolean);

  const isAuthorized = user
    ? canAccessDashboardRoute(user.role, pathname)
    : false;

  useEffect(() => {
    if (!loading && user && !isAuthorized) {
      router.replace(getDashboardFallbackRoute(user.role));
    }
  }, [isAuthorized, loading, router, user]);

  if (loading || !user) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <div className="flex items-center gap-3 rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm text-zinc-600 shadow-sm">
          <Spinner />
          Carregando painel...
        </div>
      </div>
    );
  }

  if (!isAuthorized) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <div className="rounded-2xl border border-zinc-200 bg-white px-6 py-5 text-sm text-zinc-600 shadow-sm">
          Redirecionando para uma area permitida...
        </div>
      </div>
    );
  }

  return (
    <SidebarProvider>
      <AppSidebar />
      <SidebarInset className="min-w-0">
        <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-2 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
          <div className="w-full flex items-center gap-2 px-4">
            <SidebarTrigger className="-ml-1" />
            <Separator
              orientation="vertical"
              className="mr-2 data-[orientation=vertical]:h-4"
            />
            <Breadcrumb>
              <BreadcrumbList>
                <BreadcrumbItem className="hidden md:block">
                  <BreadcrumbLink href="/dashboard">
                    Administrativo do sistema
                  </BreadcrumbLink>
                </BreadcrumbItem>
                {segments.map((segment, index) => {
                  const href = "/" + segments.slice(0, index + 1).join("/");
                  const isLast = index === segments.length - 1;

                  const label =
                    breadcrumbMap[segment] ??
                    segment.charAt(0).toUpperCase() + segment.slice(1);

                  return (
                    <div key={href} className="flex gap-3 items-center">
                      <BreadcrumbSeparator className="hidden md:block" />

                      <BreadcrumbItem>
                        {isLast ? (
                          <BreadcrumbPage>{label}</BreadcrumbPage>
                        ) : (
                          <BreadcrumbLink href={href}>
                            {label}
                          </BreadcrumbLink>
                        )}
                      </BreadcrumbItem>
                    </div>
                  );
                })}
              </BreadcrumbList>
            </Breadcrumb>
          </div>
        </header>
        <main className="min-w-0 p-4 md:p-6">
          {children}
        </main>
      </SidebarInset>
    </SidebarProvider>
  )
}

export default function DashboardLayout(
  { children }: { children: React.ReactNode }
) {
  return (
    <AuthProvider>
      <DashboardShell>{children}</DashboardShell>
    </AuthProvider>
  )
}
