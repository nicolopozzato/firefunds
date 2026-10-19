import { useTranslation } from 'react-i18next'
import type { AccountRef } from '@/types/transaction'
import EmptyValue from '@/components/EmptyValue'
import Highlight from '@/components/Highlight'

/**
 * An account's name, or an em dash for a transaction side with no account.
 * An instrument side shows its ticker and, muted, its type: "VWCE · ETF".
 * `highlight`: search terms to mark in the name.
 */
export default function AccountName({ account, highlight }: { account: AccountRef | null; highlight?: string[] }) {
  const { t } = useTranslation()

  if (account === null) {
    return <EmptyValue />
  }

  if (account.type === 'instrument' && account.instrumentType) {
    return (
      <span className="whitespace-nowrap">
        <Highlight text={account.name} terms={highlight} />
        <span className="text-muted-foreground"> · {t(`investmentInstruments.type_${account.instrumentType}`)}</span>
      </span>
    )
  }

  return <Highlight text={account.name} terms={highlight} />
}
