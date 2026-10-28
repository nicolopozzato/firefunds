import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import SegmentedControl from '@/components/ui/segmented-control'

interface SectionLink {
  label: string
  href: string
}

/**
 * Phones: a section's pages as a segmented control under the title (the
 * sidebar's sub-entries on larger screens), like an instrument chart's period.
 * Each choice opens another page, which builds the control again: it keeps
 * its sideways scroll (when it doesn't fit) under the section's name.
 */
function SectionTabs({ label, links }: { label: string; links: SectionLink[] }) {
  const location = useLocation()
  const navigate = useNavigate()
  const current = links.find((link) => link.href === location.pathname)?.href ?? links[0].href

  return (
    <div className="md:hidden">
      <SegmentedControl
        label={label}
        options={links.map((link) => ({ value: link.href, label: link.label }))}
        value={current}
        onChange={(href) => navigate(href, { replace: true })}
        fill
        scrollMemoryKey={`section:${links[0].href}`}
      />
    </div>
  )
}

/** The transaction lists: all, withdrawals, deposits, transfers, investment trades. */
export function TransactionSectionTabs() {
  const { t } = useTranslation()
  return (
    <SectionTabs
      label={t('navigation.transactions')}
      links={[
        { label: t('navigation.allTransactions'), href: '/transactions' },
        { label: t('navigation.withdrawalsShort'), href: '/transactions/withdrawal' },
        { label: t('navigation.depositsShort'), href: '/transactions/deposit' },
        { label: t('navigation.transferTransactions'), href: '/transactions/transfers' },
        { label: t('navigation.investmentTransactions'), href: '/transactions/investments' },
        { label: t('navigation.recurringTransactions'), href: '/transactions/recurring' },
      ]}
    />
  )
}

/** The investments section: overview and instruments. */
export function InvestmentSectionTabs() {
  const { t } = useTranslation()
  return (
    <SectionTabs
      label={t('navigation.investments')}
      links={[
        { label: t('navigation.investmentsOverview'), href: '/investments/overview' },
        { label: t('navigation.investmentInstruments'), href: '/investments/instruments' },
      ]}
    />
  )
}

/** The reports: income and expenses, net worth. */
export function ReportSectionTabs() {
  const { t } = useTranslation()
  return (
    <SectionTabs
      label={t('navigation.reports')}
      links={[
        { label: t('navigation.incomeExpensesReport'), href: '/reports/income-expenses' },
        { label: t('navigation.netWorthReport'), href: '/reports/net-worth' },
      ]}
    />
  )
}
