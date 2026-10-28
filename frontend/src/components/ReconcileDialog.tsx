import { useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, ListPlus, Scale } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import ChoiceCards from '@/components/ChoiceCards'
import Combobox from '@/components/Combobox'
import Field from '@/components/Field'
import { useAccount, useReconcileAccount } from '@/hooks/use-accounts'
import { useAllClassification } from '@/hooks/use-classification'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'
import { cn, formatMoney, formatShortDate, nowAsDatetimeLocal } from '@/lib/utils'
import type { Account } from '@/types/account'

type Way = 'add' | 'record'

interface ReconcileDialogProps {
  account: Account
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Checking an asset account against the bank: the user types the balance the
 * bank shows on a day and sees it beside FireFunds' one. When they match the
 * account is marked as checked; when they don't, the user either adds what
 * was forgotten (a new transaction on this account) or has the rest recorded
 * as an expense or an income of that day, so the money doesn't just vanish.
 */
export default function ReconcileDialog({ account, open, onOpenChange }: ReconcileDialogProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const isMobile = useIsMobile()
  const [day, setDay] = useState(() => nowAsDatetimeLocal().slice(0, 10))
  const [balance, setBalance] = useState('')
  const [way, setWay] = useState<Way>('record')
  const [description, setDescription] = useState(() => t('reconcile.defaultDescription'))
  const [categoryId, setCategoryId] = useState('')
  const reconcile = useReconcileAccount()
  const { data: categories } = useAllClassification('categories')

  // FireFunds' balance at the end of that day (free cash, for an investment account).
  const { data: onDay, isFetching } = useAccount(open && day ? account.id : '', day)
  const symbol = account.currencySymbol
  const typed = balance.trim() !== '' && !Number.isNaN(Number(balance))
  const ours = onDay ? Number(onDay.currentBalance) : null
  // Cents, so 0.1 + 0.2 never shows a difference.
  const difference = typed && ours !== null ? Math.round((Number(balance) - ours) * 100) / 100 : null
  const matches = difference === 0

  const close = () => {
    onOpenChange(false)
    setBalance('')
  }

  const submit = async () => {
    try {
      await reconcile.mutateAsync({
        id: account.id,
        data: {
          day,
          balance,
          settle: !matches,
          description: matches ? null : description,
          categoryId: matches ? null : categoryId || null,
        },
      })
      toast.success(t(matches ? 'reconcile.confirmed' : 'reconcile.recorded'))
      close()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  // Less in the bank: a forgotten expense from this account; more: a forgotten income to it.
  const addMissing = () => {
    const params = difference !== null && difference > 0 ? `type=deposit&destinationAccountId=${account.id}` : `type=withdrawal&sourceAccountId=${account.id}`
    close()
    navigate(`/transactions/new?${params}`)
  }

  const body = (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-4">
        <Field label={t('reconcile.day')} htmlFor="reconcileDay">
          <Input id="reconcileDay" type="date" required value={day} max={nowAsDatetimeLocal().slice(0, 10)} onChange={(e) => setDay(e.target.value)} />
        </Field>
        <Field label={t('reconcile.bankBalance')} htmlFor="reconcileBalance">
          <div className="relative">
            <Input
              id="reconcileBalance"
              type="number"
              inputMode="decimal"
              step="0.01"
              autoFocus={!isMobile}
              className="pr-9 tabular-nums"
              value={balance}
              onChange={(e) => setBalance(e.target.value)}
            />
            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">{symbol}</span>
          </div>
        </Field>
      </div>

      <dl className="space-y-1.5 rounded-xl border bg-muted/40 p-4 text-sm">
        <Line label={t('reconcile.ourBalance', { date: day ? formatShortDate(day) : '' })}>
          <span className={cn(isFetching && 'opacity-50')}>{ours !== null ? formatMoney(ours, symbol) : '—'}</span>
        </Line>
        <Line label={t('reconcile.bankBalance')}>{typed ? formatMoney(Number(balance), symbol) : '—'}</Line>
        <div className="flex items-baseline justify-between gap-4 border-t pt-2.5">
          <dt className="font-medium">{t('reconcile.difference')}</dt>
          <dd className={cn('figure-sm', difference === null || matches ? '' : difference < 0 ? 'text-negative' : 'text-positive')}>
            {difference === null ? (
              '—'
            ) : matches ? (
              <span className="flex items-center gap-1.5 text-positive">
                <CheckCircle2 className="h-4 w-4" />
                {t('reconcile.matches')}
              </span>
            ) : (
              `${difference > 0 ? '+' : ''}${formatMoney(difference, symbol)}`
            )}
          </dd>
        </div>
      </dl>

      {difference !== null && !matches && (
        <div className="space-y-4">
          <ChoiceCards
            label={t('reconcile.how')}
            value={way}
            onChange={setWay}
            options={[
              { value: 'record', label: t('reconcile.record'), description: t(difference < 0 ? 'reconcile.recordExpense' : 'reconcile.recordIncome'), icon: Scale },
              { value: 'add', label: t('reconcile.add'), description: t('reconcile.addDescription'), icon: ListPlus },
            ]}
          />
          {way === 'record' && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('transactionForm.descriptionField')} htmlFor="reconcileDescription">
                <Input id="reconcileDescription" value={description} onChange={(e) => setDescription(e.target.value)} />
              </Field>
              <Field label={t('transactionForm.category')} optional>
                <Combobox
                  options={(categories?.items ?? []).map((category) => ({ value: category.id, label: category.name }))}
                  value={categoryId}
                  onChange={setCategoryId}
                  placeholder={t('transactionForm.selectCategory')}
                />
              </Field>
            </div>
          )}
        </div>
      )}
    </div>
  )

  const primary =
    difference !== null && !matches && way === 'add' ? (
      <Button onClick={addMissing}>{t('reconcile.newTransaction')}</Button>
    ) : (
      <Button onClick={() => void submit()} disabled={difference === null || reconcile.isPending || (!matches && !description.trim())}>
        {t(difference !== null && !matches ? 'reconcile.recordAndConfirm' : 'reconcile.confirm')}
      </Button>
    )
  const cancel = (
    <Button variant="outline" onClick={close}>
      {t('common.cancel')}
    </Button>
  )

  if (isMobile) {
    return (
      <Drawer open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
        <DrawerContent>
          <DrawerHeader className="text-left">
            <DrawerTitle>{t('reconcile.title')}</DrawerTitle>
            <DrawerDescription>{t('reconcile.description')}</DrawerDescription>
          </DrawerHeader>
          <div className="overflow-y-auto px-4">{body}</div>
          <DrawerFooter className="pb-[calc(env(safe-area-inset-bottom)+1rem)] [&>button]:h-11">
            {primary}
            {cancel}
          </DrawerFooter>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('reconcile.title')}</DialogTitle>
          <DialogDescription>{t('reconcile.description')}</DialogDescription>
        </DialogHeader>
        {body}
        <DialogFooter>
          {cancel}
          {primary}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function Line({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{children}</dd>
    </div>
  )
}
