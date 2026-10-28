import { cn, formatMoney } from '@/lib/utils'

interface TransactionAmountProps {
  amount: number
  currencySymbol: string | null
  className?: string
}

/** The headline amount of a transaction detail page: integer part large, decimals and currency symbol a step smaller. */
export default function TransactionAmount({ amount, currencySymbol, className }: TransactionAmountProps) {
  const formatted = formatMoney(amount, currencySymbol)
  const [integerAndDecimals, symbol] = formatted.split(/\s/)
  // The decimals are the last separator and the digits after it, whichever separator the number format uses.
  const match = integerAndDecimals.match(/^(.*)([.,]\d+)$/)
  const integerPart = match ? match[1] : integerAndDecimals
  const decimalPart = match ? match[2] : undefined

  return (
    <span className={cn('tabular-nums', className)}>
      <span className="text-2xl font-semibold">{integerPart}</span>
      {decimalPart !== undefined && <span className="text-lg font-semibold">{decimalPart}</span>}
      {symbol && <span className="text-lg font-semibold">{'\u00a0'}{symbol}</span>}
    </span>
  )
}
