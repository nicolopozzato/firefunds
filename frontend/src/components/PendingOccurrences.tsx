import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Check, Link2 } from 'lucide-react'
import RecurringKindIcon from '@/components/RecurringKindIcon'
import { recurringAmountClass } from '@/lib/recurring'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useConfirmOccurrence, useLinkableTransactions, useLinkOccurrence, useSkipOccurrence } from '@/hooks/use-recurring'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { MobileListCard, MobileListRow } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { amountColorClass, signedAmount } from '@/lib/transaction-display'
import { handleApiError } from '@/lib/error-handler'
import { cn, currencySymbolFor, formatMoney, formatShortDate } from '@/lib/utils'
import type { Occurrence } from '@/types/recurring'

/** Due and waiting: the amount actually paid, then confirm (creates the transaction) or skip. */
export default function PendingOccurrences({ occurrences }: { occurrences: Occurrence[] }) {
  const { t } = useTranslation()

  return (
    <Card className="border-info/40">
      <CardHeader>
        <CardTitle className="text-base">{t('recurring.pendingTitle', { count: occurrences.length })}</CardTitle>
      </CardHeader>
      <CardContent className="divide-y p-0 sm:p-0">
        {occurrences.map((occurrence) => (
          <PendingRow key={occurrence.recurring.id} occurrence={occurrence} />
        ))}
      </CardContent>
    </Card>
  )
}

function PendingRow({ occurrence }: { occurrence: Occurrence }) {
  const { t } = useTranslation()
  const { recurring } = occurrence
  const [amount, setAmount] = useState(String(Number(occurrence.amount)))
  const confirm = useConfirmOccurrence()
  const skip = useSkipOccurrence()
  const [linking, setLinking] = useState(false)
  const busy = confirm.isPending || skip.isPending

  const run = async (action: 'confirm' | 'skip') => {
    try {
      if (action === 'confirm') await confirm.mutateAsync({ id: recurring.id, amount })
      else await skip.mutateAsync(recurring.id)
      toast.success(t(action === 'confirm' ? 'recurring.confirmed' : 'recurring.skipped'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 sm:px-5">
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <RecurringKindIcon kind={recurring.kind} />
        <div className="min-w-0">
          <div className="truncate font-medium">{recurring.description}</div>
          <div className="text-xs text-muted-foreground">
            {formatShortDate(occurrence.day)}
            {recurring.pending > 1 && ` · ${t('recurring.moreWaiting', { count: recurring.pending - 1 })}`}
          </div>
        </div>
      </div>
      <div className="flex w-full items-center gap-2 sm:w-auto">
        <div className="relative flex-1 sm:w-32 sm:flex-none">
          <Input
            type="number"
            inputMode="decimal"
            step="0.01"
            aria-label={t('recurring.amountPaid')}
            className={cn('pr-8 text-right tabular-nums', recurringAmountClass(recurring.kind))}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
          />
          <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
            {currencySymbolFor(recurring.currency)}
          </span>
        </div>
        <Button variant="ghost" disabled={busy} onClick={() => void run('skip')}>
          {t('recurring.skip')}
        </Button>
        <Button variant="outline" size="icon" disabled={busy} aria-label={t('recurring.alreadyEntered')} title={t('recurring.alreadyEntered')} onClick={() => setLinking(true)}>
          <Link2 className="h-4 w-4" />
        </Button>
        <Button disabled={busy || !(Number(amount) > 0)} onClick={() => void run('confirm')}>
          <Check className="mr-2 h-4 w-4" />
          {t('recurring.confirm')}
        </Button>
      </div>
      <LinkDialog occurrence={occurrence} open={linking} onOpenChange={setLinking} />
    </div>
  )
}

/** "I entered it already": the transactions on its account around its day, to pick the one it was. */
function LinkDialog({ occurrence, open, onOpenChange }: { occurrence: Occurrence; open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const { data: transactions, isLoading } = useLinkableTransactions(occurrence.recurring.id, open)
  const link = useLinkOccurrence()

  const pick = async (transactionId: string) => {
    try {
      await link.mutateAsync({ id: occurrence.recurring.id, transactionId })
      toast.success(t('recurring.linked'))
      onOpenChange(false)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const title = t('recurring.linkTitle')
  const description = t('recurring.linkDescription', { day: formatShortDate(occurrence.day) })
  const list = isLoading ? null : transactions && transactions.length > 0 ? (
    <MobileListCard>
      {transactions.map((transaction) => (
        <MobileListRow
          key={transaction.id}
          onClick={() => void pick(transaction.id)}
          title={transaction.description ?? '—'}
          subtitle={`${formatShortDate(transaction.occurredAt.slice(0, 10))} · ${[transaction.sourceAccount?.name ?? '—', transaction.destinationAccount?.name ?? '—'].join(' → ')}`}
          value={formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
          valueClassName={amountColorClass(transaction)}
        />
      ))}
    </MobileListCard>
  ) : (
    <p className="py-6 text-center text-sm text-muted-foreground">{t('recurring.noLinkable')}</p>
  )

  if (isMobile) {
    return (
      <Drawer open={open} onOpenChange={onOpenChange}>
        <DrawerContent>
          <DrawerHeader className="text-left">
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription>{description}</DrawerDescription>
          </DrawerHeader>
          <div className="max-h-[60vh] overflow-y-auto px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{list}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <div className="max-h-[60vh] overflow-y-auto">{list}</div>
      </DialogContent>
    </Dialog>
  )
}
