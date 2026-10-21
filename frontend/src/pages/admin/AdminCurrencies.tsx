import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Plus } from 'lucide-react'
import AdminTabs from '@/components/layout/AdminTabs'
import PageHeader from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useAdminCurrencies,
  useCreateAdminCurrency,
  useUpdateAdminCurrency,
  useDeleteAdminCurrency,
} from '@/hooks/use-admin'
import { handleApiError } from '@/lib/error-handler'
import type { AdminCurrency } from '@/types/admin'

interface FormState {
  code: string
  name: string
  symbol: string
  decimalPlaces: number
  isEnabled: boolean
}

function emptyForm(): FormState {
  return { code: '', name: '', symbol: '', decimalPlaces: 2, isEnabled: true }
}

function toForm(currency: AdminCurrency): FormState {
  return {
    code: currency.code,
    name: currency.name,
    symbol: currency.symbol,
    decimalPlaces: currency.decimalPlaces,
    isEnabled: currency.isEnabled,
  }
}

export default function AdminCurrencies() {
  const { t } = useTranslation()
  const { data: currencies, isLoading } = useAdminCurrencies()
  const createCurrency = useCreateAdminCurrency()
  const updateCurrency = useUpdateAdminCurrency()
  const deleteCurrency = useDeleteAdminCurrency()

  const [editingCurrency, setEditingCurrency] = useState<AdminCurrency | null>(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [form, setForm] = useState<FormState>(emptyForm())
  const [currencyToDelete, setCurrencyToDelete] = useState<AdminCurrency | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const openCreateForm = () => {
    setEditingCurrency(null)
    setForm(emptyForm())
    setIsFormOpen(true)
  }

  const openEditForm = (currency: AdminCurrency) => {
    setEditingCurrency(currency)
    setForm(toForm(currency))
    setIsFormOpen(true)
  }

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()

    try {
      if (editingCurrency) {
        await updateCurrency.mutateAsync({ id: editingCurrency.id, data: form })
      } else {
        await createCurrency.mutateAsync(form)
      }
    } catch (error) {
      toast.error(handleApiError(error))
      return
    }

    setIsFormOpen(false)
    toast.success(t('admin.currencies.saved'))
  }

  const handleConfirmDelete = async () => {
    if (!currencyToDelete) return
    setDeleteError(null)

    try {
      await deleteCurrency.mutateAsync(currencyToDelete.id)
      setCurrencyToDelete(null)
      toast.success(t('admin.currencies.deleted'))
    } catch (error) {
      setDeleteError(handleApiError(error))
    }
  }

  const isSaving = createCurrency.isPending || updateCurrency.isPending

  return (
    <div className="space-y-6">
      <AdminTabs />
      <PageHeader
        title={t('admin.currencies.title')}
        description={t('admin.currencies.description')}
        actions={
          <Button onClick={openCreateForm}>
            <Plus className="mr-2 h-4 w-4" />
            {t('admin.currencies.add')}
          </Button>
        }
      />

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="py-12 text-center text-sm text-muted-foreground">{t('common.loading')}</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('admin.currencies.code')}</TableHead>
                  <TableHead>{t('accountsCommon.name')}</TableHead>
                  <TableHead>{t('admin.currencies.symbol')}</TableHead>
                  <TableHead>{t('admin.currencies.decimalPlaces')}</TableHead>
                  <TableHead>{t('admin.currencies.enabled')}</TableHead>
                  <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {currencies?.map((currency) => (
                  <TableRow key={currency.id}>
                    <TableCell className="font-medium">{currency.code}</TableCell>
                    <TableCell>{currency.name}</TableCell>
                    <TableCell>{currency.symbol}</TableCell>
                    <TableCell className="text-muted-foreground">{currency.decimalPlaces}</TableCell>
                    <TableCell>
                      <Badge variant={currency.isEnabled ? 'success' : 'secondary'}>
                        {currency.isEnabled ? t('common.yes') : t('common.no')}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => openEditForm(currency)}>
                            <Pencil className="mr-2 h-4 w-4" />
                            {t('common.edit')}
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => {
                              setDeleteError(null)
                              setCurrencyToDelete(currency)
                            }}
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {t('common.delete')}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={isFormOpen} onOpenChange={setIsFormOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingCurrency ? t('admin.currencies.editTitle') : t('admin.currencies.add')}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="code">{t('admin.currencies.code')}</Label>
                <Input
                  id="code"
                  required
                  maxLength={3}
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="symbol">{t('admin.currencies.symbol')}</Label>
                <Input
                  id="symbol"
                  required
                  value={form.symbol}
                  onChange={(e) => setForm({ ...form, symbol: e.target.value })}
                />
              </div>
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="name">{t('accountsCommon.name')}</Label>
                <Input
                  id="name"
                  required
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="decimalPlaces">{t('admin.currencies.decimalPlaces')}</Label>
                <Input
                  id="decimalPlaces"
                  type="number"
                  min={0}
                  max={8}
                  required
                  value={form.decimalPlaces}
                  onChange={(e) => setForm({ ...form, decimalPlaces: Number(e.target.value) })}
                />
              </div>
              <div className="flex items-center gap-2 pt-6">
                <input
                  id="isEnabled"
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                  checked={form.isEnabled}
                  onChange={(e) => setForm({ ...form, isEnabled: e.target.checked })}
                />
                <Label htmlFor="isEnabled">{t('admin.currencies.enabled')}</Label>
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setIsFormOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={isSaving}>
                {t('common.save')}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!currencyToDelete} onOpenChange={(open) => !open && setCurrencyToDelete(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('admin.currencies.deleteConfirmTitle')}</DialogTitle>
            <DialogDescription>
              {t('admin.currencies.deleteConfirmDescription', { code: currencyToDelete?.code })}
            </DialogDescription>
          </DialogHeader>
          {deleteError && <p className="text-sm text-destructive">{deleteError}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={() => setCurrencyToDelete(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="destructive" onClick={handleConfirmDelete} disabled={deleteCurrency.isPending}>
              {t('common.delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
