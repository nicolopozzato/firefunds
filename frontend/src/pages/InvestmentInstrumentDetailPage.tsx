import { useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Pencil, Trash2, Upload } from 'lucide-react'
import { format, startOfYear, subDays, subMonths, subYears } from 'date-fns'
import PageHeader from '@/components/PageHeader'
import DeleteInstrumentDialog from '@/components/DeleteInstrumentDialog'
import BalanceLineChart from '@/components/BalanceLineChart'
import PricePeriodSelector, { type PricePeriod } from '@/components/PricePeriodSelector'
import ImportPricesDialog from '@/components/ImportPricesDialog'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useInvestmentInstrument,
  useInvestmentInstrumentPrices,
  useInvestmentInstrumentLivePrice,
} from '@/hooks/use-investments'
import { cn, formatMoney, formatTime24h, moneyColorClass } from '@/lib/utils'
import EmptyValue from '@/components/EmptyValue'
import { useAuth } from '@/contexts/AuthContext'
import { formatRate, whiteListShareFromRate } from '@/lib/capital-gains-tax'
import { numberFormatter } from '@/lib/utils'
import LoadFailed from '@/components/LoadFailed'

const basePath = '/investments/instruments'

function fromDateForPeriod(period: PricePeriod, today: Date): string | undefined {
  switch (period) {
    case '15d':
      return format(subDays(today, 15), 'yyyy-MM-dd')
    case '1m':
      return format(subMonths(today, 1), 'yyyy-MM-dd')
    case '6m':
      return format(subMonths(today, 6), 'yyyy-MM-dd')
    case 'ytd':
      return format(startOfYear(today), 'yyyy-MM-dd')
    case '1y':
      return format(subYears(today, 1), 'yyyy-MM-dd')
    case '5y':
      return format(subYears(today, 5), 'yyyy-MM-dd')
    case 'all':
      return undefined
  }
}

export default function InvestmentInstrumentDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user } = useAuth()
  const defaultTaxRate = user?.capitalGainsTaxRate ?? '26'
  const { id } = useParams<{ id: string }>()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [period, setPeriod] = useState<PricePeriod>('15d')

  const now = new Date()
  const periodFrom = fromDateForPeriod(period, now)

  const { data: instrument, isLoading, error, refetch } = useInvestmentInstrument(id ?? '')
  const { data: prices, isLoading: isPricesLoading } = useInvestmentInstrumentPrices(id ?? '', periodFrom)
  const { data: livePrice } = useInvestmentInstrumentLivePrice(id ?? '')

  const today = format(new Date(), 'yyyy-MM-dd')
  const hasTodayClose = (prices ?? []).some((p) => p.date === today)
  const showLivePoint = !hasTodayClose && !!livePrice?.price
  const marketState = livePrice?.marketState ?? 'unknown'
  const isMarketOpen = marketState === 'open'

  const points = useMemo(() => {
    const historical = (prices ?? []).map((p) => ({ date: p.date, balance: p.price }))
    if (showLivePoint && livePrice?.price) {
      return [...historical, { date: today, balance: livePrice.price }]
    }
    return historical
  }, [prices, showLivePoint, livePrice, today])

  const latestClose = prices && prices.length > 0 ? prices[prices.length - 1] : null
  const previousClose = prices && prices.length > 1 ? prices[prices.length - 2] : null

  const displayPrice = showLivePoint && livePrice?.price ? livePrice.price : latestClose?.price
  const referencePrice = showLivePoint ? latestClose?.price : previousClose?.price
  const change =
    displayPrice && referencePrice ? Number(displayPrice) - Number(referencePrice) : null

  if (!instrument && error) {
    return <LoadFailed error={error} onRetry={() => void refetch()} />
  }

  if (isLoading || !instrument) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('investmentInstrumentDetail.title')} />
        <Card>
          <CardContent className="space-y-4 pt-6">
            <Skeleton className="h-8 w-2/3" />
            <Skeleton className="h-6 w-1/3" />
          </CardContent>
        </Card>
      </div>
    )
  }

  const whiteListShare =
    instrument.capitalGainsTaxRate !== null
      ? whiteListShareFromRate(Number(defaultTaxRate), Number(instrument.capitalGainsTaxRate), instrument.type)
      : null

  return (
    <div className="space-y-6">
      <PageHeader
        title={instrument.name}
        description={t('investmentInstrumentDetail.title')}
        actions={
          <>
            <Button variant="outline" onClick={() => setImportOpen(true)}>
              <Upload className="mr-2 h-4 w-4" />
              {t('importPrices.button')}
            </Button>
            <Button variant="outline" onClick={() => navigate(`${basePath}/${instrument.id}/edit`)}>
              <Pencil className="mr-2 h-4 w-4" />
              {t('common.edit')}
            </Button>
            <Button
              variant="outline"
              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => setConfirmDelete(true)}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('common.delete')}
            </Button>
          </>
        }
      />

      <Card>
        <CardContent className="space-y-4 pt-6">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex flex-wrap items-end gap-x-8 gap-y-2">
              <div>
                {displayPrice ? (
                  <>
                    <div className="flex items-center gap-2">
                      <div className="text-3xl font-semibold tabular-nums">{formatMoney(displayPrice)}</div>
                      {showLivePoint && (
                        <Badge
                          variant="outline"
                          className={cn(
                            'gap-1',
                            isMarketOpen ? 'text-positive' : 'text-muted-foreground'
                          )}
                        >
                          <span
                            className={cn(
                              'h-1.5 w-1.5 rounded-full',
                              isMarketOpen ? 'animate-pulse bg-positive' : 'bg-muted-foreground'
                            )}
                          />
                          {t(`investmentInstrumentDetail.marketState_${marketState}`)}
                        </Badge>
                      )}
                    </div>
                    <div className="text-sm text-muted-foreground">
                      {showLivePoint
                        ? isMarketOpen
                          ? t('investmentInstrumentDetail.livePriceAsOf', { time: formatTime24h(livePrice?.fetchedAt ?? new Date()) })
                          : t('investmentInstrumentDetail.lastTradedPrice')
                        : t('investmentInstrumentDetail.lastPriceOn', { date: latestClose?.date })}
                    </div>
                  </>
                ) : (
                  <div className="text-sm text-muted-foreground">
                    {t('investmentInstrumentDetail.noPriceYet')}
                  </div>
                )}
              </div>
              {change !== null && (
                <div className={cn('text-sm font-medium tabular-nums', moneyColorClass(change))}>
                  {change >= 0 ? '+' : ''}
                  {formatMoney(change)}
                </div>
              )}
            </div>
            <Badge variant="outline">{t(`investmentInstruments.type_${instrument.type}`)}</Badge>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentInstruments.ticker')}</div>
              <div className="font-mono text-sm">{instrument.ticker || <EmptyValue />}</div>
            </div>
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentInstruments.isin')}</div>
              <div className="font-mono text-sm">{instrument.isin || <EmptyValue />}</div>
            </div>
            {/* Own rate in plain text; otherwise the preferences' rate, in brackets and faded (as in the list). */}
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentInstruments.taxColumn')}</div>
              <div className="text-sm tabular-nums">
                {instrument.capitalGainsTaxRate !== null ? (
                  formatRate(instrument.capitalGainsTaxRate)
                ) : (
                  <span className="text-muted-foreground/60">({formatRate(defaultTaxRate)})</span>
                )}
                {whiteListShare !== null && (
                  <span className="ml-1.5 text-xs text-muted-foreground">
                    ·{' '}
                    {t('investmentInstruments.whiteListImplied', {
                      share: `${numberFormatter({ maximumFractionDigits: 0 }).format(whiteListShare)}%`,
                    })}
                  </span>
                )}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <PricePeriodSelector value={period} onChange={setPeriod} />
      </div>

      {isPricesLoading ? (
        <Skeleton className="h-96 w-full rounded-xl" />
      ) : (
        <BalanceLineChart
          title={t('investmentInstrumentDetail.priceTrend')}
          points={points}
          emptyLabel={t('charts.noData')}
        />
      )}

      <ImportPricesDialog instrumentId={instrument.id} open={importOpen} onOpenChange={setImportOpen} />

      <DeleteInstrumentDialog
        instrument={confirmDelete ? instrument : null}
        onClose={() => setConfirmDelete(false)}
        onDeleted={() => navigate(basePath)}
      />
    </div>
  )
}
