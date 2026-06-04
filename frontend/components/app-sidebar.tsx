"use client"

import * as React from "react"
import {
  Dumbbell,
} from "lucide-react"

import { getDashboardNavSectionsForRole } from "@/lib/dashboard-access"
import { NavMain } from "@/components/nav-main"
import { NavUser } from "@/components/nav-user"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"

import { useAuth } from "@/context/AuthContext"

export function AppSidebar({ ...props }: React.ComponentProps<typeof Sidebar>) {
  const { user } = useAuth()

  const navSections = React.useMemo(() => {
    if (!user) {
      return []
    }

    return getDashboardNavSectionsForRole(user.role)
  }, [user])

  return (
    <Sidebar variant="inset" {...props}>
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild>
              <a href="/dashboard">
                <div className="bg-[var(--cor-first)] text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg">
                  <Dumbbell className="size-4" />
                </div>
                <div className="grid flex-1 text-left text-sm leading-tight">
                  <span className="truncate font-medium">
                    Painel
                  </span>
                  <span className="truncate text-xs">
                    {user ? `Painel ${user.role}` : "Painel administrativo"}
                  </span>
                </div>
              </a>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <NavMain sections={navSections} />
      </SidebarContent>

      <SidebarFooter>
        <NavUser user={{
          name: user?.name ?? "Usuario",
          email: user?.email ?? "",
          role: user?.role ?? null,
        }} />
      </SidebarFooter>
    </Sidebar>
  )
}
