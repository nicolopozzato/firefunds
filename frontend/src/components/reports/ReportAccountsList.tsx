import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import CurrencyTag from '@/components/CurrencyTag'
import { MobileListCard, MobileListRow } from '@/components/MobileList'
import { formatMoney, moneyColorClass } from '@/lib/utils'
import { signedMoney } from '@/lib/report-format'
import type { IncomeExpenseSummary, ReportAccount } from '@/types/report'

interface ReportAccountsListProps {
  accounts: ReportAccount[]
  summary: Pick<IncomeExpenseSummary, 'netWorthStart' | 'netWorthEnd' | 'netWorthChange'>
  currency: string | null
}

/** Phones: the accounts table as a list: each account's balance at the end, where it started from and the difference. */
export default function ReportAccountsList({ accounts, summary, currency }: ReportAccountsListProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const difference = (change: string) => (
    <span className={moneyColorClass(change)}>{Number(change) === 0 ? '–' : signedMoney(change, currency)}</span>
  )

  return (
    <section className="space-y-1.5">
      <h3 className="px-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{t('reports.accounts')}</h3>
      <MobileListCard>
        {accounts.map((account) => (
          <MobileListRow
            key={account.id}
            onClick={() => navigate(`/accounts/asset/${account.id}`)}
            title={
              <span className={account.isActive ? undefined : 'text-muted-foreground'}>
                {account.name}
                <CurrencyTag code={account.currencyCode} />
                {!account.isActive && <span className="ml-1.5 text-xs font-normal">({t('reports.closed')})</span>}
              </span>
            }
            subtitle={t('reports.fromAmount', { amount: formatMoney(account.start, currency) })}
            value={formatMoney(account.end, currency)}
            valueDetail={difference(account.change)}
          />
        ))}
        <MobileListRow
          title={t('reports.netWorth')}
          subtitle={t('reports.fromAmount', { amount: formatMoney(summary.netWorthStart, currency) })}
          value={formatMoney(summary.netWorthEnd, currency)}
          valueDetail={difference(summary.netWorthChange)}
        />
      </MobileListCard>
    </section>
  )
}
