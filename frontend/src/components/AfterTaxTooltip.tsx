import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatMoney, formatPercent } from '@/lib/utils'

interface AfterTaxTooltipProps {
  children: ReactNode
  /** Estimated capital gains tax if sold today; no tooltip without it. */
  estimatedTax: string | null | undefined
  currencySymbol: string | null
  /** gain: the tax and the gain left after it; value: what selling everything today would leave. */
  variant: 'gain' | 'value'
  /** The gain (for `gain`) or the total value (for `value`) the tax is taken from. */
  amount: string | number
  /** Shown next to the tax when it's a single position's rate. */
  taxRate?: string
}

/**
 * Wraps a gain or a value with a tooltip saying what would be left after
 * capital gains tax, were everything sold today. The dotted underline hints
 * that there's more on hover, or on a tap on tablets.
 */
export default function AfterTaxTooltip({ children, estimatedTax, currencySymbol, variant, amount, taxRate }: AfterTaxTooltipProps) {
  const { t } = useTranslation()

  if (estimatedTax === null || estimatedTax === undefined) {
    return <>{children}</>
  }

  const net = Number(amount) - Number(estimatedTax)
  const tax = formatMoney(estimatedTax, currencySymbol)

  return (
    <Tooltip>
      <TooltipTrigger asChild tapToOpen>
        <span className="cursor-help underline decoration-muted-foreground/40 decoration-dotted underline-offset-4">
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs space-y-1">
        <div>
          {taxRate !== undefined
            ? t('taxEstimate.taxWithRate', { amount: tax, rate: formatPercent(taxRate) })
            : t('taxEstimate.tax', { amount: tax })}
        </div>
        <div className="font-medium">
          {t(variant === 'gain' ? 'taxEstimate.netGain' : 'taxEstimate.ifSoldToday', {
            amount: formatMoney(net, currencySymbol),
          })}
        </div>
        <div className="text-xs text-muted-foreground">{t('taxEstimate.note')}</div>
      </TooltipContent>
    </Tooltip>
  )
}
