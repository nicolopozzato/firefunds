import { Fragment, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format, parseISO } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { ArrowDownCircle, ArrowUpCircle, Plus, Repeat, ShoppingCart, Banknote, type LucideIcon } from 'lucide-react'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { cn, currencySymbolFor, formatMoney, parseDate } from '@/lib/utils'
import { amountColorClass, amountCurrency, detailPath, signedAmount } from '@/lib/transaction-display'
import type { DashboardAccount } from '@/types/dashboard'

interface DashboardAccountCardProps {
  account: DashboardAccount
  /** The account's line color in the balance chart. */
  color: string
  /** Last day of the period, Y-m-d: the balance is as of then. */
  to: string
}

/** One asset account on the dashboard: balance at the end of the period, its latest transactions, a shortcut to add one. */
export default function DashboardAccountCard({ account, color, to }: DashboardAccountCardProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const isMobile = useIsMobile()
  const [menuOpen, setMenuOpen] = useState(false)
  const locale = i18n.language === 'it' ? it : enUS
  const isInvestment = account.assetKind === 'investment'

  // Shortcuts to add a transaction on this account: a menu, a panel from the bottom on phones.
  const actions: { label: string; icon: LucideIcon; className: string; href: string; separated?: boolean }[] = isInvestment
    ? [
        { label: t('dashboard.newBuy'), icon: ShoppingCart, className: 'text-info', href: `/transactions/investments/new?type=buy&accountId=${account.id}` },
        { label: t('dashboard.newSell'), icon: Banknote, className: 'text-positive', href: `/transactions/investments/new?type=sell&accountId=${account.id}` },
        { label: t('dashboard.newTransferIn'), icon: Repeat, className: 'text-info', href: `/transactions/new?type=transfer&destinationAccountId=${account.id}`, separated: true },
        { label: t('quickAdd.deposit'), icon: ArrowDownCircle, className: 'text-positive', href: `/transactions/new?type=deposit&destinationAccountId=${account.id}` },
      ]
    : [
        { label: t('quickAdd.withdrawal'), icon: ArrowUpCircle, className: 'text-negative', href: `/transactions/new?type=withdrawal&sourceAccountId=${account.id}` },
        { label: t('quickAdd.deposit'), icon: ArrowDownCircle, className: 'text-positive', href: `/transactions/new?type=deposit&destinationAccountId=${account.id}` },
        { label: t('quickAdd.transfer'), icon: Repeat, className: 'text-info', href: `/transactions/new?type=transfer&sourceAccountId=${account.id}` },
      ]

  return (
    <Card className="surface-interactive">
      <CardHeader className="space-y-0 pb-3 sm:pb-3">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden />
            <Link to={`/accounts/asset/${account.id}`} className="truncate font-semibold text-link">
              {account.name}
            </Link>
          </div>
          <div className="text-right">
            <div className={cn('figure-sm whitespace-nowrap', Number(account.balance) < 0 && 'text-negative')}>
              {formatMoney(account.balance, account.currencySymbol)}
            </div>
            <div className="text-xs text-muted-foreground">
              {t('dashboard.balanceOn', { date: format(parseISO(to), 'd MMM yyyy', { locale }) })}
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        {account.transactions.length === 0 ? (
          <p className="py-2 text-sm text-muted-foreground">{t('dashboard.noTransactionsInPeriod')}</p>
        ) : (
          <ul className="divide-y">
            {account.transactions.map((transaction) => {
              const amount = signedAmount(transaction, account.id)
              return (
                <li key={transaction.id}>
                  <Link to={detailPath(transaction)} className="-mx-2 flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-accent/50">
                    <span className="w-12 shrink-0 text-xs tabular-nums text-muted-foreground">
                      {format(parseDate(transaction.occurredAt), 'd MMM', { locale })}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm">{transaction.description ?? '—'}</span>
                    <span className={cn('shrink-0 text-sm font-medium tabular-nums', amountColorClass(transaction))}>
                      {amount > 0 ? '+' : ''}
                      {formatMoney(amount, currencySymbolFor(amountCurrency(transaction, account.id)))}
                    </span>
                  </Link>
                </li>
              )
            })}
          </ul>
        )}

        <div className="-mb-1 flex items-center justify-between">
          <Link to={`/accounts/asset/${account.id}`} className="text-xs font-medium text-muted-foreground hover:text-foreground">
            {t('dashboard.allTransactions')}
          </Link>
          {isMobile ? (
            <>
              <Button variant="ghost" size="icon" className="h-11 w-11 text-muted-foreground" aria-label={t('dashboard.newTransaction')} onClick={() => setMenuOpen(true)}>
                <Plus className="h-5 w-5" />
              </Button>
              <Drawer open={menuOpen} onOpenChange={setMenuOpen}>
                <DrawerContent>
                  <DrawerHeader>
                    <DrawerTitle>{account.name}</DrawerTitle>
                    <DrawerDescription className="sr-only">{t('dashboard.newTransaction')}</DrawerDescription>
                  </DrawerHeader>
                  <div className="px-4 pb-4">
                    <div className="divide-y overflow-hidden surface">
                      {actions.map((action) => (
                        <button
                          key={action.label}
                          type="button"
                          onClick={() => {
                            setMenuOpen(false)
                            navigate(action.href)
                          }}
                          className="flex w-full items-center gap-3 px-4 py-3.5 text-left text-sm font-medium transition-colors active:bg-accent"
                        >
                          <action.icon className={cn('h-5 w-5', action.className)} />
                          {action.label}
                        </button>
                      ))}
                    </div>
                  </div>
                </DrawerContent>
              </Drawer>
            </>
          ) : (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" aria-label={t('dashboard.newTransaction')} title={t('dashboard.newTransaction')}>
                  <Plus className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                {actions.map((action) => (
                  <Fragment key={action.label}>
                    {action.separated && <DropdownMenuSeparator />}
                    <DropdownMenuItem onClick={() => navigate(action.href)}>
                      <action.icon className={cn('mr-2 h-4 w-4', action.className)} />
                      {action.label}
                    </DropdownMenuItem>
                  </Fragment>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
