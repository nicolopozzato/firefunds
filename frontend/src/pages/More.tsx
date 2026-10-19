import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Banknote,
  ChevronRight,
  Coins,
  FileBarChart,
  LogOut,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Tag,
  Tags,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { getFullName } from '@/types/auth'
import { cn } from '@/lib/utils'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'

/** "0.1.0" -> "0.1", as at the bottom of every page on larger screens. */
const APP_VERSION = __APP_VERSION__.split('.').slice(0, 2).join('.')

interface Entry {
  label: string
  href: string
  icon: LucideIcon
  /** Background and colour of the icon's square. */
  tone: string
}

function Group({ title, entries }: { title?: string; entries: Entry[] }) {
  return (
    <section>
      {title && (
        <h2 className="mb-2 px-4 text-xs font-medium uppercase tracking-wider text-muted-foreground">{title}</h2>
      )}
      <div className="divide-y overflow-hidden rounded-2xl border bg-card">
        {entries.map((entry) => (
          <Link
            key={entry.href}
            to={entry.href}
            className="flex items-center gap-3 px-4 py-3 text-[15px] transition-colors active:bg-accent"
          >
            <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', entry.tone)}>
              <entry.icon className="h-[18px] w-[18px]" />
            </span>
            <span className="flex-1">{entry.label}</span>
            <ChevronRight className="h-4 w-4 text-muted-foreground/60" />
          </Link>
        ))}
      </div>
    </section>
  )
}

/**
 * The bottom bar's "More" tab on phones: everything the sidebar and the
 * header's user menu offer that has no tab of its own.
 */
export default function More() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()

  const initials = user ? `${user.firstName[0] ?? ''}${user.lastName[0] ?? ''}`.toUpperCase() : 'U'

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">{t('navigation.more')}</h1>

      <Link to="/profile" className="flex items-center gap-4 rounded-2xl border bg-card p-4 transition-colors active:bg-accent">
        <Avatar className="h-12 w-12">
          <AvatarFallback className="bg-gradient-to-br from-[hsl(var(--series-2))] to-[hsl(var(--series-5))] text-base text-white">{initials}</AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="truncate font-medium">{user ? getFullName(user) : ''}</p>
            {user?.isAdmin && <Badge variant="secondary" className="text-xs">{t('common.admin')}</Badge>}
          </div>
          <p className="truncate text-sm text-muted-foreground">{user?.email}</p>
        </div>
        <ChevronRight className="h-4 w-4 text-muted-foreground/60" />
      </Link>

      <Group
        title={t('navigation.accounts')}
        entries={[
          { label: t('navigation.assetAccounts'), href: '/accounts/asset', icon: Wallet, tone: 'bg-info/10 text-info' },
          { label: t('navigation.expenseAccounts'), href: '/accounts/expense', icon: ShoppingCart, tone: 'bg-negative/10 text-negative' },
          { label: t('navigation.revenueAccounts'), href: '/accounts/revenue', icon: Banknote, tone: 'bg-positive/10 text-positive' },
        ]}
      />

      <Group
        title={t('navigation.classification')}
        entries={[
          { label: t('navigation.categories'), href: '/categories', icon: Tag, tone: 'bg-warning/10 text-warning' },
          { label: t('navigation.tags'), href: '/tags', icon: Tags, tone: 'bg-invest/10 text-invest' },
        ]}
      />

      <Group
        entries={[
          { label: t('navigation.reports'), href: '/reports', icon: FileBarChart, tone: 'bg-[hsl(var(--series-3)/0.1)] text-[hsl(var(--series-3))]' },
          { label: t('preferences.title'), href: '/preferences', icon: Settings, tone: 'bg-muted text-muted-foreground' },
        ]}
      />

      {user?.isAdmin && (
        <Group
          title={t('admin.title')}
          entries={[
            { label: t('admin.users.title'), href: '/admin/users', icon: Users, tone: 'bg-muted text-muted-foreground' },
            { label: t('admin.settings.title'), href: '/admin/settings', icon: ShieldCheck, tone: 'bg-muted text-muted-foreground' },
            { label: t('admin.currencies.title'), href: '/admin/currencies', icon: Coins, tone: 'bg-muted text-muted-foreground' },
          ]}
        />
      )}

      <button
        type="button"
        onClick={logout}
        className="flex w-full items-center justify-center gap-2 rounded-2xl border bg-card px-4 py-3 text-[15px] font-medium text-destructive transition-colors active:bg-accent"
      >
        <LogOut className="h-4 w-4" />
        {t('auth.logout')}
      </button>

      <p className="text-center text-xs text-muted-foreground/60">
        FireFunds <span className="text-[10px]">v{APP_VERSION}</span>
      </p>
    </div>
  )
}
