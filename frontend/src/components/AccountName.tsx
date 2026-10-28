import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { AccountRef } from '@/types/transaction'
import EmptyValue from '@/components/EmptyValue'
import Highlight from '@/components/Highlight'

/** Where an account (or an instrument standing in as a side) has its page. */
function accountPath(account: AccountRef): string {
  return account.type === 'instrument' ? `/investments/instruments/${account.id}` : `/accounts/${account.type}/${account.id}`
}

/**
 * An account's name, or an em dash for a transaction side with no account.
 * An instrument side shows its ticker and, muted, its type: "VWCE · ETF".
 * `highlight`: search terms to mark in the name. `link`: the name opens the
 * account's page (inside a clickable row, the row's own click is left alone).
 */
export default function AccountName({ account, highlight, link }: { account: AccountRef | null; highlight?: string[]; link?: boolean }) {
  const { t } = useTranslation()

  if (account === null) {
    return <EmptyValue />
  }

  const name =
    account.type === 'instrument' && account.instrumentType ? (
      <span className="whitespace-nowrap">
        <Highlight text={account.name} terms={highlight} />
        <span className="text-muted-foreground"> · {t(`investmentInstruments.type_${account.instrumentType}`)}</span>
      </span>
    ) : (
      <Highlight text={account.name} terms={highlight} />
    )

  if (!link) return name

  return (
    <Link to={accountPath(account)} onClick={(event) => event.stopPropagation()} className="underline-offset-4 hover:underline">
      {name}
    </Link>
  )
}
