import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useImportInvestmentPrices } from '@/hooks/use-investments'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'

interface ImportPricesDialogProps {
  instrumentId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Fetches an instrument's closing prices for a range: a dialog, a panel from the bottom on phones. */
export default function ImportPricesDialog({ instrumentId, open, onOpenChange }: ImportPricesDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const importPrices = useImportInvestmentPrices(instrumentId)
  const yesterday = format(new Date(Date.now() - 86_400_000), 'yyyy-MM-dd')

  const [from, setFrom] = useState('')
  const [to, setTo] = useState(yesterday)

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (!from || !to) return

    try {
      await importPrices.mutateAsync({ from, to })
      onOpenChange(false)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const fields = (
    <div className="grid gap-4 py-4 sm:grid-cols-2">
      <div className="space-y-2">
        <Label htmlFor="import-from">{t('importPrices.from')}</Label>
        <Input
          id="import-from"
          type="date"
          required
          max={yesterday}
          value={from}
          onChange={(e) => setFrom(e.target.value)}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="import-to">{t('importPrices.to')}</Label>
        <Input
          id="import-to"
          type="date"
          required
          max={yesterday}
          value={to}
          onChange={(e) => setTo(e.target.value)}
        />
      </div>
    </div>
  )

  const buttons = (
    <>
      <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
        {t('common.cancel')}
      </Button>
      <Button type="submit" disabled={importPrices.isPending}>
        {t('importPrices.import')}
      </Button>
    </>
  )

  if (isMobile) {
    return (
      <Drawer open={open} onOpenChange={onOpenChange}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{t('importPrices.title')}</DrawerTitle>
            <DrawerDescription>{t('importPrices.description')}</DrawerDescription>
          </DrawerHeader>
          <form onSubmit={handleSubmit} className="px-4 pb-4">
            {fields}
            <div className="flex gap-2 [&>*]:h-11 [&>*]:flex-1">{buttons}</div>
          </form>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>{t('importPrices.title')}</DialogTitle>
            <DialogDescription>{t('importPrices.description')}</DialogDescription>
          </DialogHeader>
          {fields}
          <DialogFooter>{buttons}</DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
