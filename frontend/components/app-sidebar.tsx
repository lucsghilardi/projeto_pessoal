"use client"

import * as React from "react"
import {
  Sprout,
} from "lucide-react"

import { getDashboardNavForRole } from "@/lib/dashboard-access"
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
  SidebarRail,
} from "@/components/ui/sidebar"

import { useAuth } from "@/context/AuthContext"

export function AppSidebar({ ...props }: React.ComponentProps<typeof Sidebar>) {
  const { user } = useAuth()

  const navEntries = React.useMemo(() => {
    if (!user) {
      return []
    }

    return getDashboardNavForRole(user.role)
  }, [user])

  return (
    <Sidebar variant="inset" collapsible="icon" {...props}>
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild>
              <a href="/dashboard">
                <div className="bg-[var(--cor-first)] flex aspect-square size-8 items-center justify-center rounded-lg text-white">
                  <Sprout className="size-4" />
                </div>
                <div className="grid flex-1 text-left leading-tight">
                  <span className="ff-presicav-hv truncate text-lg tracking-wide">
                    Raiz
                  </span>
                  <span className="truncate text-xs text-sidebar-foreground/70">
                    Painel pessoal
                  </span>
                </div>
              </a>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <NavMain entries={navEntries} />
      </SidebarContent>

      <SidebarFooter>
        <NavUser user={{
          name: user?.name ?? "Usuario",
          email: user?.email ?? "",
          role: user?.role ?? null,
        }} />
      </SidebarFooter>

      <SidebarRail />
    </Sidebar>
  )
}
