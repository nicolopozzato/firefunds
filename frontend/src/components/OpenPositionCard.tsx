import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { differenceInCalendarDays, format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { Card, CardContent } from '@/components/ui/card'
import PositionMiniChart from '@/components/PositionMiniChart'
import { cn, formatMoney, formatPercent, formatQuantity, moneyColorClass, parseDate } from '@/lib/utils'
import EmptyValue from '@/components/EmptyValue'
import AfterTaxTooltip from '@/components/AfterTaxTooltip'
import type { OpenPosition } from '@/types/investment'

interface OpenPositionCardProps {
  position: OpenPosition
  currencySymbol: string | null
  /** Percentage shown next to `weightLabel`; null when it can't be weighed (no price yet). */
  weight: number | string | null
  weightLabel: string
  /** Shown under the value and gain. */
  extra?: ReactNode
  /** Shown under the whole card's content. */
  children?: ReactNode
}

/** A labelled figure of a position (also the per-account figures in the investments overview on phones). */
export function PositionStat({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="truncate text-sm font-medium tabular-nums text-foreground">{children}</div>
    </div>
  )
}

/** One open position: value and gain, its figures, and its value vs. invested capital over the range. */
export default function OpenPositionCard({ position, currencySymbol, weight, weightLabel, extra, children }: OpenPositionCardProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const openedOn = position.openedOn ? parseDate(position.openedOn) : null

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex flex-col gap-6 lg:flex-row lg:items-center">
          {/* grid-cols-1: a column that can shrink, so a long instrument name is truncated instead of widening the page. */}
          <div className="grid flex-1 grid-cols-1 gap-6 sm:grid-cols-2 lg:min-w-0">
            <div className="min-w-0 space-y-3">
              <div>
                <div className="text-lg font-semibold">{position.instrumentTicker ?? position.instrumentName}</div>
                <div className="truncate text-sm text-muted-foreground" title={position.instrumentName}>
                  {position.instrumentName}
                </div>
              </div>

              <div>
                <div className="text-2xl font-semibold tabular-nums">
                  {position.currentValue !== null ? formatMoney(position.currentValue, currencySymbol) : <EmptyValue />}
                </div>
                {position.gainAmount !== null && position.gainPercent !== null && (
                  <div className={cn('text-sm font-medium tabular-nums', moneyColorClass(position.gainAmount))}>
                    <AfterTaxTooltip
                      variant="gain"
                      amount={position.gainAmount}
                      estimatedTax={position.estimatedTax}
                      currencySymbol={currencySymbol}
                      taxRate={position.taxRate}
                    >
                      {Number(position.gainAmount) >= 0 ? '+' : ''}
                      {formatMoney(position.gainAmount, currencySymbol)}
                    </AfterTaxTooltip>{' '}
                    · {Number(position.gainPercent) >= 0 ? '+' : ''}
                    {formatPercent(position.gainPercent)}
                  </div>
                )}
                {extra}
              </div>
            </div>

            <div className="grid grid-cols-2 content-center gap-x-6 gap-y-3">
              <PositionStat label={t('accountDetail.positionQuantity')}>{formatQuantity(position.quantity)}</PositionStat>
              <PositionStat label={t('accountDetail.positionCostBasis')}>
                {formatMoney(position.costBasis, currencySymbol)}
              </PositionStat>
              <PositionStat label={t('accountDetail.positionAverageCost')}>
                {formatMoney(position.averageCost, currencySymbol)}
              </PositionStat>
              <PositionStat label={t('accountDetail.positionCurrentPrice')}>
                {position.currentPrice !== null ? formatMoney(position.currentPrice, currencySymbol) : <EmptyValue />}
              </PositionStat>
              <PositionStat label={weightLabel}>{weight !== null ? formatPercent(weight, 1) : <EmptyValue />}</PositionStat>
              <PositionStat label={t('accountDetail.positionOpenedOn')}>
                {openedOn ? (
                  <>
                    {format(openedOn, 'd MMM yyyy', { locale })}
                    <span className="ml-1 font-normal text-muted-foreground">
                      ({t('accountDetail.positionDaysHeld', { count: differenceInCalendarDays(new Date(), openedOn) })})
                    </span>
                  </>
                ) : (
                  <EmptyValue />
                )}
              </PositionStat>
            </div>
          </div>

          <div className="w-full lg:w-2/5 lg:shrink-0">
            <PositionMiniChart history={position.history} currency={currencySymbol} />
          </div>
        </div>

        {children}
      </CardContent>
    </Card>
  )
}
