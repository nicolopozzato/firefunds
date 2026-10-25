import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { addMonths, endOfMonth, format, isSameMonth, parseISO, startOfMonth, subDays } from 'date-fns'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { MobileListCard, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import PriceEditDialog from '@/components/PriceEditDialog'
import { useInvestmentInstrumentPrices } from '@/hooks/use-investments'
import { useIsMobile } from '@/hooks/use-media-query'
import { dateLocale, periodLabel } from '@/lib/report-format'
import { cn, currencySymbolFor, formatDateTime, formatMoney, formatPercent, moneyColorClass } from '@/lib/utils'
import { changesFromPrevious } from '@/lib/instrument-prices'
import type { InvestmentPrice } from '@/types/investment'

/** Days fetched before the month, so its first day has a previous price to compare with (a long weekend, a holiday). */
const LOOKBACK_DAYS = 10

interface InstrumentPriceListProps {
  instrumentId: string
  /** The currency its prices are in. */
  currencyCode: string
  /** The month opened first: the latest price's, when there is one. */
  initialDate?: string | null
}

/**
 * An instrument's prices one month at a time, newest first, with where each
 * comes from: imported (the provider's close), carried over from the day
 * before (no close that day, hidden unless asked), or the user's own. A
 * table on larger screens, a list on phones; a row opens PriceEditDialog,
 * where it is corrected, restored or removed, as "Add a price" adds one.
 * One price per day.
 */
export default function InstrumentPriceList({ instrumentId, currencyCode, initialDate }: InstrumentPriceListProps) {
  const { t, i18n } = useTranslation()
  const symbol = currencySymbolFor(currencyCode)
  const isMobile = useIsMobile()
  const [month, setMonth] = useState(() => startOfMonth(initialDate ? parseISO(initialDate) : new Date()))
  const [showFilled, setShowFilled] = useState(false)
  const [editing, setEditing] = useState<InvestmentPrice | 'new' | null>(null)

  const monthStart = format(month, 'yyyy-MM-dd')
  // A few days before the month too, for its first day's change; not shown.
  const { data: prices, isLoading } = useInvestmentInstrumentPrices(
    instrumentId,
    format(subDays(month, LOOKBACK_DAYS), 'yyyy-MM-dd'),
    format(endOfMonth(month), 'yyyy-MM-dd')
  )
  const changes = useMemo(() => changesFromPrevious(prices ?? []), [prices])
  const rows = useMemo(
    () =>
      [...(prices ?? [])]
        .reverse()
        .filter((price) => price.date >= monthStart && (showFilled || price.source !== 'filled')),
    [prices, showFilled, monthStart]
  )
  const isCurrentMonth = isSameMonth(month, new Date())

  /** "+0,10 € · +0,10%", coloured; nothing for the first price there is. */
  const changeLabel = (price: InvestmentPrice) => {
    const change = changes.get(price.date)
    if (!change) return null
    const sign = change.amount > 0 ? '+' : ''
    return (
      <span className={cn('tabular-nums', moneyColorClass(change.amount))}>
        {sign}
        {formatMoney(change.amount, symbol)} · {sign}
        {formatPercent(change.percent)}
      </span>
    )
  }

  const sourceBadge = (price: InvestmentPrice) => (
    <Badge variant={price.source === 'manual' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[11px] font-normal">
      {t(`instrumentPrices.source_${price.source}`)}
    </Badge>
  )
  const importedNote = (price: InvestmentPrice) =>
    price.source === 'manual' && price.originalPrice !== null
      ? t('instrumentPrices.imported', { price: formatMoney(price.originalPrice, symbol) })
      : null

  const monthNavigator = (
    <div className="flex items-center gap-1">
      <Button
        variant="ghost"
        size="icon"
        className="h-8 w-8"
        aria-label={t('instrumentPrices.previousMonth')}
        title={t('instrumentPrices.previousMonth')}
        onClick={() => setMonth((current) => addMonths(current, -1))}
      >
        <ChevronLeft className="h-4 w-4" />
      </Button>
      <span className="min-w-32 text-center text-sm font-medium">
        {periodLabel(format(month, 'yyyy-MM'), 'month', dateLocale(i18n.language), true)}
      </span>
      <Button
        variant="ghost"
        size="icon"
        className="h-8 w-8"
        aria-label={t('instrumentPrices.nextMonth')}
        title={t('instrumentPrices.nextMonth')}
        disabled={isCurrentMonth}
        onClick={() => setMonth((current) => addMonths(current, 1))}
      >
        <ChevronRight className="h-4 w-4" />
      </Button>
    </div>
  )

  const toolbar = (
    <div className="flex flex-wrap items-center justify-between gap-3">
      {monthNavigator}
      <div className="flex flex-wrap items-center gap-4">
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-input"
            checked={showFilled}
            onChange={(e) => setShowFilled(e.target.checked)}
          />
          {t('instrumentPrices.showFilled')}
        </label>
        <Button type="button" variant="outline" size="sm" onClick={() => setEditing('new')}>
          <Plus className="mr-2 h-4 w-4" />
          {t('instrumentPrices.add')}
        </Button>
      </div>
    </div>
  )

  const empty = <p className="py-6 text-center text-sm text-muted-foreground">{t('instrumentPrices.empty')}</p>

  return (
    <div className="space-y-3">
      {toolbar}

      {isMobile ? (
        isLoading ? (
          <MobileListSkeleton rows={4} />
        ) : rows.length === 0 ? (
          empty
        ) : (
          <MobileListCard>
            {rows.map((price) => (
              <MobileListRow
                key={price.date}
                title={formatDateTime(price.date)}
                subtitle={
                  <span className="flex flex-wrap items-center gap-1.5">
                    {sourceBadge(price)}
                    {importedNote(price)}
                  </span>
                }
                value={formatMoney(price.price, symbol)}
                valueClassName={cn(price.source === 'filled' && 'text-muted-foreground')}
                valueDetail={changeLabel(price)}
                onClick={() => setEditing(price)}
              />
            ))}
          </MobileListCard>
        )
      ) : (
        <Card>
          <CardContent className="p-0">
            {isLoading ? (
              <div className="space-y-2 p-4">
                {[0, 1, 2].map((i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : rows.length === 0 ? (
              empty
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('instrumentPrices.date')}</TableHead>
                    <TableHead>{t('instrumentPrices.source')}</TableHead>
                    <TableHead className="text-right">{t('instrumentPrices.price')}</TableHead>
                    <TableHead className="text-right">{t('instrumentPrices.change')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {/* h-10: the other tables' rows get their height from an action button; these have none. */}
                  {rows.map((price) => (
                    <TableRow key={price.date} className="h-10 cursor-pointer" onClick={() => setEditing(price)}>
                      <TableCell>{formatDateTime(price.date)}</TableCell>
                      <TableCell>
                        <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                          {sourceBadge(price)}
                          {importedNote(price)}
                        </span>
                      </TableCell>
                      <TableCell
                        className={cn('text-right font-medium tabular-nums', price.source === 'filled' && 'text-muted-foreground')}
                      >
                        {formatMoney(price.price, symbol)}
                      </TableCell>
                      <TableCell className="text-right text-sm">{changeLabel(price)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      )}

      <PriceEditDialog instrumentId={instrumentId} currencyCode={currencyCode} price={editing} known={prices ?? []} onClose={() => setEditing(null)} />
    </div>
  )
}
