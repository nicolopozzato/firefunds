import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { RotateCcw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useManualPrices } from '@/hooks/use-investments'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'
import { currencySymbolFor, formatDateTime, formatMoney } from '@/lib/utils'
import type { InvestmentPrice } from '@/types/investment'

interface PriceEditDialogProps {
  /** The currency the prices are in. */
  currencyCode: string
  instrumentId: string
  /** The day to correct, or 'new' to add one; null: closed. */
  price: InvestmentPrice | 'new' | null
  /** The prices on screen: adding a day that already has one says it will be corrected. */
  known: InvestmentPrice[]
  onClose: () => void
}

/**
 * Adds a price for a day, or corrects one: a dialog, a panel from the bottom
 * on phones. A corrected imported day can go back to the imported value, a
 * day only the user entered can be removed.
 */
export default function PriceEditDialog({ instrumentId, currencyCode, price, known, onClose }: PriceEditDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const isOpen = price !== null
  const title = t(price === 'new' ? 'instrumentPrices.add' : 'instrumentPrices.edit')

  const body = isOpen && (
    <PriceForm
      symbol={currencySymbolFor(currencyCode)}
      // A fresh form for every day opened.
      key={price === 'new' ? 'new' : price.date}
      instrumentId={instrumentId}
      price={price}
      known={known}
      onDone={onClose}
    />
  )

  if (isMobile) {
    return (
      <Drawer open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription className="sr-only">{t('instrumentPrices.manualHint')}</DrawerDescription>
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
          <DialogDescription className="sr-only">{t('instrumentPrices.manualHint')}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  )
}

function PriceForm({
  instrumentId,
  symbol,
  price,
  known,
  onDone,
}: {
  instrumentId: string
  symbol: string
  price: InvestmentPrice | 'new'
  known: InvestmentPrice[]
  onDone: () => void
}) {
  const { t } = useTranslation()
  const manual = useManualPrices(instrumentId)
  const isNew = price === 'new'
  const [date, setDate] = useState(isNew ? format(new Date(), 'yyyy-MM-dd') : price.date)
  const [value, setValue] = useState(isNew ? '' : String(Number(price.price)))
  const isBusy = manual.set.isPending || manual.restore.isPending || manual.remove.isPending
  const existingDay = isNew ? known.find((other) => other.date === date) : undefined

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
    if (date && value) void run(() => manual.set.mutateAsync({ date, price: value }))
  }

  let extra: ReactNode = null
  if (!isNew && price.source === 'manual') {
    extra =
      price.originalPrice !== null ? (
        <Button type="button" variant="outline" disabled={isBusy} onClick={() => void run(() => manual.restore.mutateAsync(price.date))}>
          <RotateCcw className="mr-2 h-4 w-4" />
          {t('instrumentPrices.restoreTo', { price: formatMoney(price.originalPrice, symbol) })}
        </Button>
      ) : (
        <Button
          type="button"
          variant="outline"
          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
          disabled={isBusy}
          onClick={() => void run(() => manual.remove.mutateAsync(price.date))}
        >
          <Trash2 className="mr-2 h-4 w-4" />
          {t('instrumentPrices.remove')}
        </Button>
      )
  }

  return (
    <form onSubmit={save} className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="price-date">{t('instrumentPrices.date')}</Label>
          {isNew ? (
            <Input
              id="price-date"
              type="date"
              required
              max={format(new Date(), 'yyyy-MM-dd')}
              value={date}
              onChange={(e) => setDate(e.target.value)}
            />
          ) : (
            <div id="price-date" className="flex h-10 items-center text-sm">
              {formatDateTime(date)}
            </div>
          )}
        </div>
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="price-value">{t('instrumentPrices.price')}</Label>
          <Input
            id="price-value"
            type="number"
            step="0.0001"
            min="0.0001"
            required
            autoFocus={!isNew}
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        {existingDay
          ? t('instrumentPrices.dayExists', { price: formatMoney(existingDay.price, symbol) })
          : t('instrumentPrices.manualHint')}
      </p>

      {/* Back to the imported value, or remove the day: a row of its own, across the width. */}
      {extra && <div className="[&>*]:w-full">{extra}</div>}

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
