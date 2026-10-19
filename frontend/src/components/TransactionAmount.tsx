import { cn, formatMoney } from '@/lib/utils'

interface TransactionAmountProps {
  amount: number
  currencySymbol: string | null
  className?: string
}

/** The headline amount of a transaction detail page: integer part large, decimals and currency symbol a step smaller. */
export default function TransactionAmount({ amount, currencySymbol, className }: TransactionAmountProps) {
  const formatted = formatMoney(amount, currencySymbol)
  const [integerAndDecimals, symbol] = formatted.split(' ')
  const [integerPart, decimalPart] = integerAndDecimals.split(',')

  return (
    <span className={cn('tabular-nums', className)}>
      <span className="text-2xl font-semibold">{integerPart}</span>
      {decimalPart !== undefined && <span className="text-lg font-semibold">,{decimalPart}</span>}
      {symbol && <span className="text-lg font-semibold"> {symbol}</span>}
    </span>
  )
}
