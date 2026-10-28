import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { addMonths, endOfMonth, format, isSameMonth, startOfMonth } from 'date-fns'
import { Plus, TriangleAlert } from 'lucide-react'
import ExchangeRateEditDialog from '@/components/ExchangeRateEditDialog'
import { MobileListCard, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useExchangeRateDays, useExchangeRatesOverview } from '@/hooks/use-exchange-rates'
import { useIsMobile } from '@/hooks/use-media-query'
import { dateLocale, periodLabel } from '@/lib/report-format'
import { cn, currencySymbolFor, formatDateTime, formatExchangeRate } from '@/lib/utils'
import type { ExchangeRate, ExchangeRateCurrency } from '@/types/exchange-rate'
import Stepper from '@/components/Stepper'

/**
 * The exchange rates of the currencies the user holds or quotes in, in their
 * own currency, one month at a time: the shared rate of each day, or the
 * user's own where they corrected it. A tab of the preferences.
 */
export default function ExchangeRatesPanel() {
  const { t, i18n } = useTranslation()
  const isMobile = useIsMobile()
  const [searchParams, setSearchParams] = useSearchParams()
  const { data: overview, isLoading: isOverviewLoading } = useExchangeRatesOverview()
  const [month, setMonth] = useState(() => startOfMonth(new Date()))
  const [editing, setEditing] = useState<ExchangeRate | 'new' | null>(null)

  const own = overview?.currency ?? 'EUR'
  const ownSymbol = currencySymbolFor(own)
  const currencies = overview?.currencies ?? []
  const requested = searchParams.get('currency')
  const selected = currencies.find((item) => item.currency === requested) ?? currencies[0]
  const currency = selected?.currency

  const { data: days, isLoading } = useExchangeRateDays(currency, format(month, 'yyyy-MM-dd'), format(endOfMonth(month), 'yyyy-MM-dd'))
  const rows = useMemo(() => days ?? [], [days])
  const isCurrentMonth = isSameMonth(month, new Date())

  const sourceBadge = (rate: ExchangeRate) => (
    <Badge variant={rate.source === 'manual' ? 'secondary' : 'outline'} className="font-normal">
      {t(`exchangeRates.source_${rate.source}`)}
    </Badge>
  )
  const originalNote = (rate: ExchangeRate) =>
    rate.source === 'manual' && rate.originalRate !== null ? t('exchangeRates.original', { rate: formatExchangeRate(rate.originalRate) }) : null

  const currencyChips = (
    <div className="flex flex-wrap gap-2">
      {currencies.map((item) => (
        <Button
          key={item.currency}
          type="button"
          size="sm"
          variant={item.currency === currency ? 'default' : 'outline'}
          onClick={() => setSearchParams({ tab: 'rates', currency: item.currency }, { replace: true })}
        >
          {item.status !== 'ok' && <TriangleAlert className="mr-1.5 h-3.5 w-3.5 text-warning" aria-label={t(`exchangeRates.status_${item.status}`)} />}
          {item.currency}
          {item.rate !== null && <span className="ml-2 font-normal tabular-nums opacity-80">{formatExchangeRate(item.rate)}</span>}
        </Button>
      ))}
    </div>
  )

  const monthNavigator = (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <Stepper
        previousLabel={t('instrumentPrices.previousMonth')}
        nextLabel={t('instrumentPrices.nextMonth')}
        nextDisabled={isCurrentMonth}
        onPrevious={() => setMonth((current) => addMonths(current, -1))}
        onNext={() => setMonth((current) => addMonths(current, 1))}
      >
        {periodLabel(format(month, 'yyyy-MM'), 'month', dateLocale(i18n.language), true)}
      </Stepper>
      <Button type="button" variant="outline" size="sm" onClick={() => setEditing('new')}>
        <Plus className="mr-2 h-4 w-4" />
        {t('exchangeRates.addShort')}
      </Button>
    </div>
  )

  return (
    <div className="space-y-4">
      <p className="max-w-3xl text-sm text-muted-foreground">{t('exchangeRates.description', { own })}</p>

      {isOverviewLoading ? (
        <Skeleton className="h-40 w-full rounded-xl" />
      ) : currency === undefined ? (
        <p className="py-10 text-center text-sm text-muted-foreground">{t('exchangeRates.none')}</p>
      ) : (
        <div className="max-w-3xl space-y-4">
          {currencyChips}
          {selected && selected.status !== 'ok' && <RateStatusNote item={selected} own={own} />}
          {monthNavigator}

          {isMobile ? (
            isLoading ? (
              <MobileListSkeleton rows={4} />
            ) : (
              <MobileListCard>
                {rows.map((rate) => (
                  <MobileListRow
                    key={rate.date}
                    title={formatDateTime(rate.date)}
                    subtitle={
                      <span className="flex flex-wrap items-center gap-1.5">
                        {sourceBadge(rate)}
                        {originalNote(rate)}
                      </span>
                    }
                    value={`${formatExchangeRate(rate.rate)} ${ownSymbol}`}
                    valueClassName={cn(rate.source === 'filled' && 'text-muted-foreground')}
                    onClick={() => setEditing(rate)}
                  />
                ))}
              </MobileListCard>
            )
          ) : (
            <Card>
              <CardContent className="pt-4 sm:pt-5">
                {isLoading ? (
                  <div className="space-y-2">
                    {[0, 1, 2].map((i) => (
                      <Skeleton key={i} className="h-8 w-full" />
                    ))}
                  </div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{t('exchangeRates.date')}</TableHead>
                        <TableHead className="text-right">{t('exchangeRates.rate', { currency })}</TableHead>
                        <TableHead>{t('exchangeRates.origin')}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {rows.map((rate) => (
                        <TableRow key={rate.date} className="cursor-pointer" onClick={() => setEditing(rate)}>
                          <TableCell className="whitespace-nowrap">{formatDateTime(rate.date)}</TableCell>
                          <TableCell className={cn('text-right tabular-nums', rate.source === 'filled' && 'text-muted-foreground')}>
                            {formatExchangeRate(rate.rate)} {ownSymbol}
                          </TableCell>
                          <TableCell>
                            <span className="flex flex-wrap items-center gap-1.5">
                              {sourceBadge(rate)}
                              <span className="text-xs text-muted-foreground">{originalNote(rate)}</span>
                            </span>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {currency !== undefined && <ExchangeRateEditDialog currency={currency} ownCurrency={own} rate={editing} onClose={() => setEditing(null)} />}
    </div>
  )
}

/** Why the figures in this currency are wrong or may be: no rate at all, or shared rates that stopped. */
function RateStatusNote({ item, own }: { item: ExchangeRateCurrency; own: string }) {
  const { t } = useTranslation()

  return (
    <div className="flex gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
      <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
      <p>
        {item.status === 'missing'
          ? t('exchangeRates.missingNote', { currency: item.currency, own })
          : t('exchangeRates.staleNote', { currency: item.currency, date: item.latestDate ? formatDateTime(item.latestDate) : '' })}
      </p>
    </div>
  )
}
