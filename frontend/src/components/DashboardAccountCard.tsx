import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format, parseISO } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { ArrowDownCircle, ArrowUpCircle, Plus, Repeat, ShoppingCart, Banknote } from 'lucide-react'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { cn, formatMoney, moneyColorClass, parseDate } from '@/lib/utils'
import { isTransfer } from '@/lib/transaction-sides'
import type { DashboardAccount } from '@/types/dashboard'
import type { Transaction } from '@/types/transaction'

/** What the transaction did to this account's cash: positive = money in. */
function signedAmount(transaction: Transaction, accountId: string): number {
  const amount = Number(transaction.amount)
  if (transaction.kind === 'investmentBuy') return -amount
  if (transaction.kind === 'investmentSell') return amount
  return transaction.destinationAccount?.id === accountId ? amount : -amount
}

function detailPath(transaction: Transaction): string {
  return transaction.kind === 'money' ? `/transactions/${transaction.id}` : `/transactions/investments/${transaction.id}`
}

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
  const locale = i18n.language === 'it' ? it : enUS
  const isInvestment = account.assetKind === 'investment'

  return (
    <Card>
      <CardHeader className="space-y-0 p-4 pb-3 sm:p-6 sm:pb-3">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden />
            <Link to={`/accounts/asset/${account.id}`} className="truncate font-semibold hover:underline">
              {account.name}
            </Link>
          </div>
          <div className="text-right">
            <div className={cn('font-semibold tabular-nums', Number(account.balance) < 0 && 'text-negative')}>
              {formatMoney(account.balance, account.currencySymbol)}
            </div>
            <div className="text-xs text-muted-foreground">
              {t('dashboard.balanceOn', { date: format(parseISO(to), 'd MMM yyyy', { locale }) })}
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3 px-4 pb-4 sm:px-6 sm:pb-6">
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
                    <span className={cn('shrink-0 text-sm font-medium tabular-nums', isTransfer(transaction) ? 'text-info' : moneyColorClass(amount))}>
                      {amount > 0 ? '+' : ''}
                      {formatMoney(amount, account.currencySymbol)}
                    </span>
                  </Link>
                </li>
              )
            })}
          </ul>
        )}

        <div className="flex items-center justify-between">
          <Link to={`/accounts/asset/${account.id}`} className="text-xs text-muted-foreground hover:text-foreground hover:underline">
            {t('dashboard.allTransactions')}
          </Link>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm">
                <Plus className="mr-1 h-4 w-4" />
                {t('dashboard.newTransaction')}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              {isInvestment ? (
                <>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/investments/new?type=buy&accountId=${account.id}`)}>
                    <ShoppingCart className="mr-2 h-4 w-4 text-info" />
                    {t('dashboard.newBuy')}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/investments/new?type=sell&accountId=${account.id}`)}>
                    <Banknote className="mr-2 h-4 w-4 text-positive" />
                    {t('dashboard.newSell')}
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={() => navigate(`/transactions/new?type=transfer&destinationAccountId=${account.id}`)}>
                    <Repeat className="mr-2 h-4 w-4 text-info" />
                    {t('dashboard.newTransferIn')}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/new?type=deposit&destinationAccountId=${account.id}`)}>
                    <ArrowDownCircle className="mr-2 h-4 w-4 text-positive" />
                    {t('navigation.depositTransactions')}
                  </DropdownMenuItem>
                </>
              ) : (
                <>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/new?type=withdrawal&sourceAccountId=${account.id}`)}>
                    <ArrowUpCircle className="mr-2 h-4 w-4 text-negative" />
                    {t('navigation.withdrawalTransactions')}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/new?type=deposit&destinationAccountId=${account.id}`)}>
                    <ArrowDownCircle className="mr-2 h-4 w-4 text-positive" />
                    {t('navigation.depositTransactions')}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => navigate(`/transactions/new?type=transfer&sourceAccountId=${account.id}`)}>
                    <Repeat className="mr-2 h-4 w-4 text-info" />
                    {t('navigation.transferTransactions')}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </CardContent>
    </Card>
  )
}
