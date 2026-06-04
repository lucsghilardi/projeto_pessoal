"use client"

import { usePathname } from "next/navigation"

import { type DashboardNavSection, isDashboardItemActive } from "@/lib/dashboard-access"
import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import {
  ChevronRight
} from "lucide-react";

export function NavMain({
  sections,
}: {
  sections: DashboardNavSection[]
}) {
  const pathname = usePathname()

  return (
    <>
      {sections.map((section) => (
        <SidebarGroup key={section.label}>
          <SidebarGroupLabel>{section.label}</SidebarGroupLabel>
          <SidebarMenu>
            {section.items.map((item) => (
              <SidebarMenuItem key={item.url}>
                <SidebarMenuButton
                  asChild
                  isActive={isDashboardItemActive(pathname, item.url)}
                  tooltip={item.title}
                >
                  <a href={item.url}>
                    <item.icon />
                    <span>{item.title}</span>
                    <ChevronRight className="ml-auto opacity-50" />
                  </a>
                </SidebarMenuButton>
              </SidebarMenuItem>
            ))}
          </SidebarMenu>
        </SidebarGroup>
      ))}
    </>
  )
}
