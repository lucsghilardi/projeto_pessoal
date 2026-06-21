"use client"

import { useMemo, useState } from "react"
import { usePathname } from "next/navigation"
import { ChevronRight, Search } from "lucide-react"

import {
  type DashboardNavEntry,
  type DashboardNavGroup,
  type DashboardNavLink,
  isDashboardItemActive,
} from "@/lib/dashboard-access"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarInput,
  useSidebar,
} from "@/components/ui/sidebar"

export function NavMain({ entries }: { entries: DashboardNavEntry[] }) {
  const pathname = usePathname()
  const { state, isMobile } = useSidebar()
  const [query, setQuery] = useState("")

  const isCollapsed = state === "collapsed" && !isMobile
  const q = query.trim().toLowerCase()
  const searching = !isCollapsed && q.length > 0

  const allLinks = useMemo<DashboardNavLink[]>(
    () => entries.flatMap((entry) => (entry.kind === "link" ? [entry] : entry.items)),
    [entries],
  )

  const results = useMemo(
    () => (searching ? allLinks.filter((link) => link.title.toLowerCase().includes(q)) : []),
    [allLinks, q, searching],
  )

  return (
    <>
      <SidebarGroup className="group-data-[collapsible=icon]:hidden">
        <SidebarGroupContent className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground" />
          <SidebarInput
            placeholder="Buscar no menu..."
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            className="pl-8"
            aria-label="Buscar no menu"
          />
        </SidebarGroupContent>
      </SidebarGroup>

      {searching ? (
        <SidebarGroup>
          <SidebarGroupLabel>Resultados</SidebarGroupLabel>
          <SidebarMenu>
            {results.length === 0 ? (
              <p className="px-2 py-1.5 text-xs text-muted-foreground">Nenhum resultado.</p>
            ) : (
              results.map((item) => (
                <SidebarMenuItem key={item.url}>
                  <SidebarMenuButton
                    asChild
                    isActive={isDashboardItemActive(pathname, item.url)}
                    tooltip={item.title}
                  >
                    <a href={item.url}>
                      <item.icon />
                      <span>{item.title}</span>
                    </a>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))
            )}
          </SidebarMenu>
        </SidebarGroup>
      ) : (
        <SidebarGroup>
          <SidebarMenu>
            {entries.map((entry) =>
              entry.kind === "link" ? (
                <NavLinkItem key={entry.url} item={entry} pathname={pathname} />
              ) : isCollapsed ? (
                <NavGroupCollapsed key={entry.label} group={entry} pathname={pathname} />
              ) : (
                <NavGroupExpanded key={entry.label} group={entry} pathname={pathname} />
              ),
            )}
          </SidebarMenu>
        </SidebarGroup>
      )}
    </>
  )
}

function NavLinkItem({ item, pathname }: { item: DashboardNavLink; pathname: string }) {
  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        asChild
        isActive={isDashboardItemActive(pathname, item.url)}
        tooltip={item.title}
      >
        <a href={item.url}>
          <item.icon />
          <span>{item.title}</span>
        </a>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

function NavGroupExpanded({
  group,
  pathname,
}: {
  group: DashboardNavGroup
  pathname: string
}) {
  const GroupIcon = group.icon
  const hasActive = group.items.some((item) => isDashboardItemActive(pathname, item.url))

  return (
    <Collapsible asChild defaultOpen={hasActive} className="group/collapsible">
      <SidebarMenuItem>
        <CollapsibleTrigger asChild>
          <SidebarMenuButton tooltip={group.label} isActive={hasActive}>
            <GroupIcon />
            <span>{group.label}</span>
            <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
          </SidebarMenuButton>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <SidebarMenuSub>
            {group.items.map((item) => (
              <SidebarMenuSubItem key={item.url}>
                <SidebarMenuSubButton
                  asChild
                  isActive={isDashboardItemActive(pathname, item.url)}
                >
                  <a href={item.url}>
                    <item.icon />
                    <span>{item.title}</span>
                  </a>
                </SidebarMenuSubButton>
              </SidebarMenuSubItem>
            ))}
          </SidebarMenuSub>
        </CollapsibleContent>
      </SidebarMenuItem>
    </Collapsible>
  )
}

function NavGroupCollapsed({
  group,
  pathname,
}: {
  group: DashboardNavGroup
  pathname: string
}) {
  const GroupIcon = group.icon
  const hasActive = group.items.some((item) => isDashboardItemActive(pathname, item.url))

  return (
    <SidebarMenuItem>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <SidebarMenuButton isActive={hasActive}>
            <GroupIcon />
            <span>{group.label}</span>
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent side="right" align="start" className="min-w-48">
          <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
          {group.items.map((item) => (
            <DropdownMenuItem key={item.url} asChild>
              <a href={item.url}>
                <item.icon className="size-4" />
                <span>{item.title}</span>
              </a>
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  )
}
