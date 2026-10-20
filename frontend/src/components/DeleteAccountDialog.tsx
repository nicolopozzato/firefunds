import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAccountDeletionPreview, useDeleteAccount } from '@/hooks/use-accounts'
import { handleApiError } from '@/lib/error-handler'
import { formatMoney } from '@/lib/utils'
import type { Account, DeleteAccountOptions } from '@/types/account'

interface DeleteAccountDialogProps {
  /** The account to delete; the dialog is open while this is set. */
  account: Account | null
  onClose: () => void
  onDeleted?: () => void
}

type Mode = 'transfer' | 'delete'

/**
 * Confirms deleting an account. If it still has transactions, says how many
 * and lets the user either move them to another account of the same kind and
 * currency, or delete them together with the account.
 */
export default function DeleteAccountDialog({ account, onClose, onDeleted }: DeleteAccountDialogProps) {
  const { t } = useTranslation()
  const { data: preview, isLoading } = useAccountDeletionPreview(account?.id ?? null)
  const deleteAccount = useDeleteAccount()
  const [mode, setMode] = useState<Mode>('transfer')
  const [targetId, setTargetId] = useState('')

  useEffect(() => {
    setMode('transfer')
    setTargetId('')
  }, [account?.id])

  const inUse =
    !!preview && (preview.openingBalance !== null || preview.transactions + preview.investmentTransactions + preview.adjustments > 0)
  const canTransfer = !!preview && preview.transferTargets.length > 0
  const effectiveMode: Mode = canTransfer ? mode : 'delete'

  const handleConfirm = async () => {
    if (!account) return

    let options: DeleteAccountOptions = {}
    if (inUse) {
      options = effectiveMode === 'transfer' ? { transferTo: targetId } : { deleteTransactions: true }
    }

    try {
      await deleteAccount.mutateAsync({ id: account.id, options })
      onClose()
      onDeleted?.()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const confirmDisabled =
    isLoading || !preview || deleteAccount.isPending || (inUse && effectiveMode === 'transfer' && targetId === '')

  return (
    <Dialog open={!!account} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('accountsCommon.deleteConfirmTitle')}</DialogTitle>
          <DialogDescription>
            {inUse
              ? t('accountsCommon.deleteInUse', { name: account?.name })
              : t('accountsCommon.deleteConfirmDescription', { name: account?.name })}
          </DialogDescription>
        </DialogHeader>

        {inUse && preview && (
          <div className="space-y-4 text-sm">
            <ul className="list-disc space-y-1 pl-5">
              {preview.openingBalance !== null && (
                <li>
                  {t('accountsCommon.deleteOpeningBalance', {
                    amount: formatMoney(preview.openingBalance, account?.currencySymbol ?? null),
                  })}
                </li>
              )}
              {preview.transactions > 0 && <li>{t('accountsCommon.deleteTransactionsCount', { count: preview.transactions })}</li>}
              {preview.adjustments > 0 && <li>{t('accountsCommon.deleteAdjustmentsCount', { count: preview.adjustments })}</li>}
              {preview.investmentTransactions > 0 && (
                <li>{t('accountsCommon.deleteInvestmentTransactionsCount', { count: preview.investmentTransactions })}</li>
              )}
              {preview.openPositions > 0 && <li>{t('accountsCommon.deleteOpenPositionsCount', { count: preview.openPositions })}</li>}
            </ul>

            <div className="space-y-3">
              <label className={`flex items-start gap-2 ${canTransfer ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`}>
                <input
                  type="radio"
                  name="delete-account-mode"
                  className="mt-1"
                  checked={effectiveMode === 'transfer'}
                  disabled={!canTransfer}
                  onChange={() => setMode('transfer')}
                />
                <span className="space-y-2">
                  <span className="block font-medium">{t('accountsCommon.deleteTransferOption')}</span>
                  {preview.openingBalance !== null && (
                    <span className="block text-muted-foreground">
                      {t('accountsCommon.deleteTransferOpeningBalanceHint', { name: account?.name })}
                    </span>
                  )}
                  {canTransfer ? (
                    <Select value={targetId} onValueChange={setTargetId} disabled={effectiveMode !== 'transfer'}>
                      <SelectTrigger>
                        <SelectValue placeholder={t('accountsCommon.deleteTransferSelect')} />
                      </SelectTrigger>
                      <SelectContent>
                        {preview.transferTargets.map((target) => (
                          <SelectItem key={target.id} value={target.id}>
                            {target.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <span className="block text-muted-foreground">{t('accountsCommon.deleteTransferNone')}</span>
                  )}
                </span>
              </label>

              <label className="flex cursor-pointer items-start gap-2">
                <input
                  type="radio"
                  name="delete-account-mode"
                  className="mt-1"
                  checked={effectiveMode === 'delete'}
                  onChange={() => setMode('delete')}
                />
                <span>
                  <span className="block font-medium">{t('accountsCommon.deleteAllOption')}</span>
                  <span className="block text-muted-foreground">{t('accountsCommon.deleteAllWarning')}</span>
                </span>
              </label>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="destructive" onClick={handleConfirm} disabled={confirmDisabled}>
            {t('common.delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
