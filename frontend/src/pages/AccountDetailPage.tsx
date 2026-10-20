import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Pencil, Trash2 } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import BalanceLineChart from '@/components/BalanceLineChart'
import AccountValueHistoryChart from '@/components/AccountValueHistoryChart'
import AccountOpenPositionsSection from '@/components/AccountOpenPositionsSection'
import PieChart from '@/components/PieChart'
import AccountTransactionsTable from '@/components/AccountTransactionsTable'
import DeleteAccountDialog from '@/components/DeleteAccountDialog'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useAccount,
  useAccountBalanceHistory,
  useAccountValueHistory,
  useAccountDistributions,
  useAccountTypeFromPath,
} from '@/hooks/use-accounts'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { cn, formatDateTime, formatMoney, formatPercent, moneyColorClass, totalAccountValue } from '@/lib/utils'
import { useState } from 'react'
import AfterTaxTooltip from '@/components/AfterTaxTooltip'
import LoadFailed from '@/components/LoadFailed'

export default function AccountDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const type = useAccountTypeFromPath()
  const { timeframe } = useTimeframe()
  const [confirmDelete, setConfirmDelete] = useState(false)

  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')

  // Balance, invested value and gain at the end of the selected period, like the dashboard's boxes.
  const { data: account, isLoading, error, refetch } = useAccount(id ?? '', to)
  const isInvestmentAccount = account?.assetKind === 'investment'
  const { data: history, isLoading: isHistoryLoading } = useAccountBalanceHistory(id ?? '', from, to)
  const { data: valueHistory, isLoading: isValueHistoryLoading } = useAccountValueHistory(
    isInvestmentAccount ? (id ?? '') : '',
    from,
    to
  )
  const { data: distributions, isLoading: isDistributionsLoading } = useAccountDistributions(id ?? '', from, to)

  if (!account && error) {
    return <LoadFailed error={error} onRetry={() => void refetch()} />
  }

  if (isLoading || !account) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('accountDetail.title')} />
        <Card>
          <CardContent className="space-y-4 pt-6">
            <Skeleton className="h-8 w-2/3" />
            <Skeleton className="h-6 w-1/3" />
            <Skeleton className="h-6 w-1/2" />
          </CardContent>
        </Card>
      </div>
    )
  }

  const totalValue = totalAccountValue(account)

  return (
    // A column rather than space-y, so phones can move the pies after the transactions.
    <div className="flex flex-col gap-4 md:gap-6">
      <PageHeader
        title={account.name}
        description={t('accountDetail.title')}
        compactActions
        actions={
          <PageActions
            actions={[
              {
                label: t('common.edit'),
                icon: Pencil,
                primary: true,
                onClick: () => navigate(`/accounts/${type}/${account.id}/edit`),
              },
              { label: t('common.delete'), icon: Trash2, destructive: true, onClick: () => setConfirmDelete(true) },
            ]}
          />
        }
      />

      <Card>
        <CardContent className="space-y-4 pt-6">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex flex-wrap items-end gap-x-8 gap-y-2">
              <div>
                <div
                  className={cn(
                    'text-3xl font-semibold tabular-nums',
                    moneyColorClass(isInvestmentAccount ? totalValue : account.currentBalance)
                  )}
                >
                  {isInvestmentAccount ? (
                    <AfterTaxTooltip
                      variant="value"
                      amount={totalValue}
                      estimatedTax={account.estimatedTax}
                      currencySymbol={account.currencySymbol}
                    >
                      {formatMoney(totalValue, account.currencySymbol)}
                    </AfterTaxTooltip>
                  ) : (
                    formatMoney(account.currentBalance, account.currencySymbol)
                  )}
                </div>
                <div className="text-sm text-muted-foreground">{t('accountDetail.balanceOn', { date: formatDateTime(account.balanceDate) })}</div>
              </div>

              {isInvestmentAccount && (
                <>
                  <div>
                    <div className="text-xl font-semibold tabular-nums text-foreground">
                      {formatMoney(account.currentBalance, account.currencySymbol)}
                    </div>
                    <div className="text-sm text-muted-foreground">{t('accountDetail.available')}</div>
                  </div>
                  <div>
                    <div className="text-xl font-semibold tabular-nums text-foreground">
                      {formatMoney(account.investedValue ?? '0', account.currencySymbol)}
                    </div>
                    <div className="text-sm text-muted-foreground">{t('accountDetail.investments')}</div>
                  </div>
                  {account.gainAmount !== null && (
                    <div>
                      <div className={cn('text-xl font-semibold tabular-nums', moneyColorClass(account.gainAmount))}>
                        <AfterTaxTooltip
                          variant="gain"
                          amount={account.gainAmount}
                          estimatedTax={account.estimatedTax}
                          currencySymbol={account.currencySymbol}
                        >
                          {Number(account.gainAmount) >= 0 ? '+' : ''}
                          {formatMoney(account.gainAmount, account.currencySymbol)}
                        </AfterTaxTooltip>
                        <span className="ml-1.5 text-xs">
                          ({Number(account.gainPercent) >= 0 ? '+' : ''}
                          {formatPercent(account.gainPercent ?? '0')})
                        </span>
                      </div>
                      <div className="text-sm text-muted-foreground">{t('accountDetail.gain')}</div>
                    </div>
                  )}
                </>
              )}

              {account.iban && (
                <div>
                  <div className="font-mono text-sm">{account.iban}</div>
                  <div className="text-sm text-muted-foreground">{t('accountsCommon.iban')}</div>
                </div>
              )}
            </div>

            <div className="flex items-center gap-2">
              {account.assetKind && (
                <Badge variant="outline">{t(`accountsCommon.assetKind_${account.assetKind}`)}</Badge>
              )}
              <Badge variant={account.isActive ? 'success' : 'secondary'}>
                {account.isActive ? t('common.yes') : t('common.no')}
              </Badge>
            </div>
          </div>

          {account.notes && (
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('accountsCommon.notes')}</div>
              <p className="whitespace-pre-wrap text-sm">{account.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {isInvestmentAccount ? (
        isValueHistoryLoading ? (
          <Skeleton className="h-96 w-full rounded-xl" />
        ) : (
          <AccountValueHistoryChart
            title={t('accountDetail.valueTrend')}
            points={valueHistory ?? []}
            currency={account.currencySymbol}
            emptyLabel={t('charts.noData')}
          />
        )
      ) : isHistoryLoading ? (
        <Skeleton className="h-96 w-full rounded-xl" />
      ) : (
        <BalanceLineChart
          title={t('accountDetail.balanceTrend')}
          points={history ?? []}
          currency={account.currencySymbol}
          emptyLabel={t('charts.noData')}
        />
      )}

      {isInvestmentAccount && (
        <AccountOpenPositionsSection
          accountId={account.id}
          from={from}
          to={to}
          currencySymbol={account.currencySymbol}
        />
      )}

      {!isInvestmentAccount && (
        <div className="grid grid-cols-1 gap-4 max-md:order-last md:grid-cols-2">
          {/* grid-cols-1: a column that can shrink, or a long legend widens the page on phones. */}
          {isDistributionsLoading ? (
            <>
              <Skeleton className="h-64 w-full rounded-xl" />
              <Skeleton className="h-64 w-full rounded-xl" />
            </>
          ) : (
            <>
              <PieChart
                title={t('accountDetail.expensesByCategory')}
                entries={distributions?.expenses ?? []}
                currency={account.currencySymbol ?? undefined}
                emptyLabel={t('charts.noData')}
              />
              <PieChart
                title={t('accountDetail.incomeByCategory')}
                entries={distributions?.income ?? []}
                currency={account.currencySymbol ?? undefined}
                emptyLabel={t('charts.noData')}
              />
            </>
          )}
        </div>
      )}

      <AccountTransactionsTable accountId={account.id} isInvestmentAccount={isInvestmentAccount} />

      <DeleteAccountDialog
        account={confirmDelete ? account : null}
        onClose={() => setConfirmDelete(false)}
        onDeleted={() => navigate(`/accounts/${type}`)}
      />
    </div>
  )
}
