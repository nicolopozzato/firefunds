import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import CurrencyTag from '@/components/CurrencyTag'
import { cn, formatMoney, moneyColorClass } from '@/lib/utils'
import { signedMoney } from '@/lib/report-format'
import type { IncomeExpenseSummary, ReportAccount } from '@/types/report'

interface ReportAccountsTableProps {
  accounts: ReportAccount[]
  summary: Pick<IncomeExpenseSummary, 'netWorthStart' | 'netWorthEnd' | 'netWorthChange'>
  currency: string | null
}

/**
 * Each asset account at the start and at the end of the range, as in
 * Firefly III's report; an investment account's value includes what it
 * holds (shown apart on hover). The total is the net worth.
 */
export default function ReportAccountsTable({ accounts, summary, currency }: ReportAccountsTableProps) {
  const { t } = useTranslation()

  const heldTitle = (account: ReportAccount, field: 'investedStart' | 'investedEnd') =>
    account[field] !== null ? t('reports.ofWhichHeld', { amount: formatMoney(account[field] ?? '0', currency) }) : undefined

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="py-2 pr-2 text-left font-medium">{t('reports.account')}</th>
            <th className="px-2 py-2 text-right font-medium">{t('reports.startBalance')}</th>
            <th className="px-2 py-2 text-right font-medium">{t('reports.endBalance')}</th>
            <th className="py-2 pl-2 text-right font-medium">{t('reports.difference')}</th>
          </tr>
        </thead>
        <tbody>
          {accounts.map((account) => (
            <tr key={account.id} className="border-b last:border-b-0 hover:bg-muted/50">
              <td className="max-w-48 truncate py-1.5 pr-2">
                <Link to={`/accounts/asset/${account.id}`} className={cn('hover:underline', !account.isActive && 'text-muted-foreground')}>
                  {account.name}
                </Link>
                <CurrencyTag code={account.currencyCode} />
                {!account.isActive && <span className="ml-1.5 text-xs text-muted-foreground">({t('reports.closed')})</span>}
              </td>
              <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums" title={heldTitle(account, 'investedStart')}>
                {formatMoney(account.start, currency)}
              </td>
              <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums" title={heldTitle(account, 'investedEnd')}>
                {formatMoney(account.end, currency)}
              </td>
              <td className={cn('whitespace-nowrap py-1.5 pl-2 text-right tabular-nums', moneyColorClass(account.change))}>
                {Number(account.change) === 0 ? '–' : signedMoney(account.change, currency)}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t font-semibold">
            <td className="py-2 pr-2">{t('reports.netWorth')}</td>
            <td className="whitespace-nowrap px-2 py-2 text-right tabular-nums">{formatMoney(summary.netWorthStart, currency)}</td>
            <td className="whitespace-nowrap px-2 py-2 text-right tabular-nums">{formatMoney(summary.netWorthEnd, currency)}</td>
            <td className={cn('whitespace-nowrap py-2 pl-2 text-right tabular-nums', moneyColorClass(summary.netWorthChange))}>
              {signedMoney(summary.netWorthChange, currency)}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  )
}
