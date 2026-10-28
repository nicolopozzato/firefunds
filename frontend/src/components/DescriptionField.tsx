import { useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Input } from '@/components/ui/input'
import { useDescriptionSuggestions } from '@/hooks/use-transactions'
import { useTradeDescriptionSuggestions } from '@/hooks/use-investment-transactions'
import { useDebouncedValue } from '@/hooks/use-search'
import { cn, currencySymbolFor, formatMoney } from '@/lib/utils'
import type { Transaction } from '@/types/transaction'
import type { InvestmentTransaction } from '@/types/investment'

/** One line of the list: the description, what it was between, and its amount. */
interface Suggestion {
  id: string
  description: string
  detail: string
  aside: string
}

interface SuggestingFieldProps<T> {
  id: string
  value: string
  onChange: (value: string) => void
  required?: boolean
  /** A past entry picked from the list: the form copies what it shares with it. */
  onPick?: (item: T) => void
  /** The past entries offered for a text (none when it is empty). */
  useItems: (text: string) => { data?: T[] }
  describe: (item: T) => Suggestion
}

/**
 * A description field offering, while it is typed, the past entries with the
 * same words, like a browser's autocomplete: a plain list under the field,
 * arrows and Enter to choose, Esc or typing on to ignore it.
 */
function SuggestingField<T>({ id, value, onChange, required, onPick, useItems, describe }: SuggestingFieldProps<T>) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const query = useDebouncedValue(value, 200)
  const { data } = useItems(onPick && open ? query : '')
  // Nothing to offer when the text already is one of them.
  const items = (data ?? []).filter((item) => describe(item).description.toLowerCase() !== value.trim().toLowerCase())
  const visible = open && items.length > 0

  const pick = (item: T) => {
    onChange(describe(item).description)
    onPick?.(item)
    setOpen(false)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (!visible) return
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      const step = event.key === 'ArrowDown' ? 1 : -1
      setActive((current) => (current + step + items.length) % items.length)
    } else if (event.key === 'Enter') {
      event.preventDefault()
      pick(items[Math.min(active, items.length - 1)])
    } else if (event.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <div className="relative">
      <Input
        id={id}
        required={required}
        autoComplete="off"
        role="combobox"
        aria-expanded={visible}
        aria-controls={`${id}-suggestions`}
        value={value}
        onChange={(event) => {
          onChange(event.target.value)
          setActive(0)
          setOpen(true)
        }}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
      />
      {visible && (
        <ul
          id={`${id}-suggestions`}
          role="listbox"
          aria-label={t('transactionForm.suggestions')}
          className="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-md border bg-popover py-1 text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95"
        >
          {items.map((item, index) => {
            const line = describe(item)
            return (
              <li
                key={line.id}
                role="option"
                aria-selected={index === active}
                // mousedown, not click: the field's blur would close the list first.
                onMouseDown={(event) => {
                  event.preventDefault()
                  pick(item)
                }}
                onMouseEnter={() => setActive(index)}
                className={cn('flex cursor-pointer items-center gap-3 px-3 py-2 text-sm', index === active && 'bg-accent')}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{line.description}</span>
                  <span className="block truncate text-xs text-muted-foreground">{line.detail}</span>
                </span>
                <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{line.aside}</span>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

interface DescriptionFieldProps<T> {
  id: string
  value: string
  onChange: (value: string) => void
  required?: boolean
  onPick?: (item: T) => void
}

/** A money transaction's description: past ones show their accounts, category and amount. */
export default function DescriptionField(props: DescriptionFieldProps<Transaction>) {
  return (
    <SuggestingField
      {...props}
      useItems={useDescriptionSuggestions}
      describe={(transaction) => ({
        id: transaction.id,
        description: transaction.description ?? '',
        detail:
          [transaction.sourceAccount?.name ?? '—', transaction.destinationAccount?.name ?? '—'].join(' → ') +
          (transaction.categoryName ? ` · ${transaction.categoryName}` : ''),
        aside: formatMoney(transaction.amount, currencySymbolFor(transaction.currency)),
      })}
    />
  )
}

/** A trade's description: past ones show their account, instrument and units. */
export function TradeDescriptionField(props: DescriptionFieldProps<InvestmentTransaction>) {
  return (
    <SuggestingField
      {...props}
      useItems={useTradeDescriptionSuggestions}
      describe={(trade) => ({
        id: trade.id,
        description: trade.description ?? '',
        detail: `${trade.account.name} · ${trade.instrumentTicker ?? trade.instrumentName}`,
        aside: formatMoney(trade.amount, currencySymbolFor(trade.currency)),
      })}
    />
  )
}
