import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Activity, BarChart3, Flame, LayoutGrid, LineChart, Radar, Star, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Deals',
        url: '/deals',
        icon: Flame,
    },
    {
        title: 'Избранное',
        url: '/favorites',
        icon: Star,
    },
    {
        title: 'Мои сделки',
        url: '/trades',
        icon: Wallet,
    },
    {
        title: 'Отчёты',
        url: '/reports',
        icon: BarChart3,
    },
    {
        title: 'Анализ ниш',
        url: '/niches',
        icon: Activity,
    },
    {
        title: 'Источники',
        url: '/sources',
        icon: Radar,
    },
    {
        title: 'Рынок продаж',
        url: '/market',
        icon: LineChart,
    },
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
