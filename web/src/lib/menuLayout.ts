import {
  Activity, Archive, Award, BadgeCheck, BarChart3, Bell, BookMarked, BookOpen, Briefcase, Bug, Building2, Calendar, CalendarDays, CalendarRange, ClipboardCheck, ClipboardList, Clock, Cloud, Compass, Cpu, Database, DoorOpen,
  FileSearch, FileText, Flag, FlaskConical, FolderOpen, Gauge, Gift, Globe, GraduationCap, Handshake, Heart, HelpCircle, Home, Inbox, Key, KanbanSquare, Landmark, Layers, LayoutDashboard, LayoutGrid, LayoutTemplate, Library, LifeBuoy,
  LineChart, Lightbulb, ListChecks, Lock, Mail, Map as MapIcon, MapPin, Medal, Megaphone, MessagesSquare, Monitor, Package, PackageOpen, Palette, PieChart, Presentation, Puzzle, Radio, Rocket, School, School2, Search, Settings2, Shield, ShieldAlert,
  ShieldCheck, Sparkles, Star, Target, Trophy, UserCheck, UserCog, Users, Video, Wallet, Wrench, Zap, FilePenLine,
} from 'lucide-react'
import type { ComponentType } from 'react'
import type { Group } from '@/components/admin/menuCatalog'

export type MenuIcon = ComponentType<{ className?: string }>

/** The icons an administrator can give a menu button. The saved value is the key. */
export const MENU_ICONS: Record<string, MenuIcon> = {
  Activity, Archive, Award, BadgeCheck, BarChart3, Bell, BookMarked, BookOpen, Briefcase, Bug, Building2, Calendar, CalendarDays, CalendarRange, ClipboardCheck, ClipboardList, Clock, Cloud, Compass, Cpu, Database, DoorOpen,
  FileSearch, FileText, Flag, FlaskConical, FolderOpen, Gauge, Gift, Globe, GraduationCap, Handshake, Heart, HelpCircle, Home, Inbox, Key, KanbanSquare, Landmark, Layers, LayoutDashboard, LayoutGrid, LayoutTemplate, Library, LifeBuoy,
  LineChart, Lightbulb, ListChecks, Lock, Mail, Map: MapIcon, MapPin, Medal, Megaphone, MessagesSquare, Monitor, Package, PackageOpen, Palette, PieChart, Presentation, Puzzle, Radio, Rocket, School, School2, Search, Settings2, Shield, ShieldAlert,
  ShieldCheck, Sparkles, Star, Target, Trophy, UserCheck, UserCog, Users, Video, Wallet, Wrench, Zap, FilePenLine,
}

export type MenuLayout = {
  sections: { id: string; title_ar: string | null; title_en: string | null; items: string[] }[]
  icons: Record<string, string>
}

export const EMPTY_LAYOUT: MenuLayout = { sections: [], icons: {} }

/** A section made by the administrator (the built-in ones keep their own ids). */
export const isCustomSection = (id: string) => id.startsWith('c_')

/**
 * Lays the administrator's arrangement over the default menu: sections in their order and with their names, buttons in
 * the section they were moved to, icons replaced. A button the arrangement does not mention (a feature added later)
 * stays in its default section, so nothing ever disappears. Permissions and feature switches are applied afterwards.
 */
export function applyLayout(defaults: Group[], layout: MenuLayout | undefined, lang: 'ar' | 'en'): Group[] {
  const all = new Map(defaults.flatMap((g) => g.items.map((i) => [i.to, i] as const)))
  const sections = layout?.sections ?? []
  const icons = layout?.icons ?? {}
  const placed = new Set<string>()
  const out: Group[] = sections.map((s) => {
    const own = s.items.filter((to) => all.has(to) && !placed.has(to))
    own.forEach((to) => placed.add(to))
    const base = defaults.find((g) => g.id === s.id)
    return { id: s.id, title: (lang === 'ar' ? s.title_ar : s.title_en) || base?.title || '', items: own.map((to) => all.get(to)!) }
  })
  for (const g of defaults) {
    const rest = g.items.filter((i) => !placed.has(i.to))
    if (!rest.length) continue
    const home = out.find((o) => o.id === g.id)
    if (home) home.items.push(...rest)
    else out.push({ id: g.id, title: g.title, items: rest })
  }
  return out.map((g) => ({ ...g, items: g.items.map((i) => (icons[i.to] && MENU_ICONS[icons[i.to]] ? { ...i, icon: MENU_ICONS[icons[i.to]] } : i)) }))
}

/** The arrangement as it stands now, ready to edit and save (every button listed, in its section). */
export function toLayout(groups: Group[], previous: MenuLayout | undefined): MenuLayout {
  const saved = new Map((previous?.sections ?? []).map((s) => [s.id, s]))
  return {
    sections: groups.map((g) => ({ id: g.id, title_ar: saved.get(g.id)?.title_ar ?? null, title_en: saved.get(g.id)?.title_en ?? null, items: g.items.map((i) => i.to) })),
    icons: { ...(previous?.icons ?? {}) },
  }
}
