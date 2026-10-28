import { useState, type FormEvent } from 'react'
import { format } from 'date-fns'
import { useTranslation } from 'react-i18next'
import { RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useExchangeRateEdits } from '@/hooks/use-exchange-rates'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'
import { currencySymbolFor, formatDateTime, formatExchangeRate } from '@/lib/utils'
import type { ExchangeRate } from '@/types/exchange-rate'

interface ExchangeRateEditDialogProps {
  currency: string
  /** The user's own currency. */
  ownCurrency: string
  /** The day to correct, or 'new' to add one; null: closed. */
  rate: ExchangeRate | 'new' | null
  onClose: () => void
}

/**
 * Corrects a day's rate, or adds one, for the user alone: a dialog, a panel
 * from the bottom on phones. A corrected day can go back to the shared rate.
 */
export default function ExchangeRateEditDialog({ currency, ownCurrency, rate, onClose }: ExchangeRateEditDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const isOpen = rate !== null
  const title = t(rate === 'new' ? 'exchangeRates.add' : 'exchangeRates.edit', { currency })

  const body = isOpen && <RateForm key={rate === 'new' ? 'new' : rate.date} currency={currency} ownCurrency={ownCurrency} rate={rate} onDone={onClose} />

  if (isMobile) {
    return (
      <Drawer open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription className="sr-only">{t('exchangeRates.editHint')}</DrawerDescription>
          </DrawerHeader>
          <div className="px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{body}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{t('exchangeRates.editHint')}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  )
}

function RateForm({
  currency,
  ownCurrency,
  rate,
  onDone,
}: {
  currency: string
  ownCurrency: string
  rate: ExchangeRate | 'new'
  onDone: () => void
}) {
  const { t } = useTranslation()
  const edits = useExchangeRateEdits(currency)
  const isNew = rate === 'new'
  const [date, setDate] = useState(isNew ? format(new Date(), 'yyyy-MM-dd') : rate.date)
  const [value, setValue] = useState(isNew ? '' : String(Number(rate.rate)))
  const isBusy = edits.set.isPending || edits.revert.isPending
  const ownSymbol = currencySymbolFor(ownCurrency)

  const run = async (action: () => Promise<unknown>) => {
    try {
      await action()
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const save = (event: FormEvent) => {
    event.preventDefault()
    if (date && value) void run(() => edits.set.mutateAsync({ date, rate: value }))
  }

  return (
    <form onSubmit={save} className="space-y-4">
      {isNew && (
        <div className="space-y-2">
          <Label htmlFor="rate-date">{t('exchangeRates.date')}</Label>
          <Input id="rate-date" type="date" required max={format(new Date(), 'yyyy-MM-dd')} value={date} onChange={(e) => setDate(e.target.value)} />
        </div>
      )}
      <div className="space-y-2">
        <Label htmlFor="rate-value">
          {t('exchangeRates.rateLabel', { currency, own: ownCurrency, date: date ? formatDateTime(date) : '…' })}
        </Label>
        <div className="flex items-center gap-2">
          <span className="whitespace-nowrap text-sm text-muted-foreground">1 {currency} =</span>
          <Input
            id="rate-value"
            type="number"
            step="0.00000001"
            min="0.00000001"
            required
            autoFocus={!isNew}
            className="tabular-nums"
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
          <span className="text-sm text-muted-foreground">{ownSymbol}</span>
        </div>
      </div>

      <p className="text-xs text-muted-foreground">{t(isNew ? 'exchangeRates.addHint' : 'exchangeRates.editHint')}</p>

      {!isNew && rate.source === 'manual' && rate.originalRate !== null && (
        <Button type="button" variant="outline" className="w-full" disabled={isBusy} onClick={() => void run(() => edits.revert.mutateAsync(rate.date))}>
          <RotateCcw className="mr-2 h-4 w-4" />
          {t('exchangeRates.revert', { rate: formatExchangeRate(rate.originalRate) })}
        </Button>
      )}

      <div className="flex justify-end gap-2 border-t pt-4 max-sm:[&>*]:flex-1">
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={isBusy}>
          {t('common.save')}
        </Button>
      </div>
    </form>
  )
}
