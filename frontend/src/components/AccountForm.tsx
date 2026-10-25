import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Landmark, TrendingUp } from 'lucide-react'
import { toast } from 'sonner'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import Combobox from '@/components/Combobox'
import { useCreateAccount, useUpdateAccount } from '@/hooks/use-accounts'
import { useAuth } from '@/contexts/AuthContext'
import { currencyLabel, useCurrencies, useCurrencyOptions } from '@/hooks/use-currencies'
import { useFormFill } from '@/hooks/use-form-fill'
import { handleApiError } from '@/lib/error-handler'
import { cn } from '@/lib/utils'
import type { Account, AccountType, AssetAccountKind } from '@/types/account'
import { Badge } from '@/components/ui/badge'
import AccountHistoryCard from '@/components/AccountHistoryCard'
import FormActions from '@/components/FormActions'
import AccountAdjustmentsField from '@/components/AccountAdjustmentsField'
import { adjustmentRows, type AdjustmentRow } from '@/lib/account-adjustments'

interface AccountFormProps {
  type: AccountType
  title: string
  description: string
  account?: Account
}

interface FormState {
  name: string
  iban: string
  bic: string
  accountNumber: string
  notes: string
  isActive: boolean
  currencyCode: string
  openingBalance: string
  openingBalanceDate: string
  adjustments: AdjustmentRow[]
  assetKind: AssetAccountKind | ''
}

const ASSET_KINDS: AssetAccountKind[] = ['checking', 'investment']

const ASSET_KIND_ICONS: Record<AssetAccountKind, typeof Landmark> = {
  checking: Landmark,
  investment: TrendingUp,
}

function initialState(account?: Account, defaultCurrency = ''): FormState {
  return {
    name: account?.name ?? '',
    iban: account?.iban ?? '',
    bic: account?.bic ?? '',
    accountNumber: account?.accountNumber ?? '',
    notes: account?.notes ?? '',
    isActive: account?.isActive ?? true,
    currencyCode: account?.currencyCode ?? defaultCurrency,
    openingBalance: account?.openingBalance ?? '0.00',
    openingBalanceDate: account?.openingBalanceDate ?? '',
    adjustments: adjustmentRows(account?.adjustments),
    assetKind: account?.assetKind ?? '',
  }
}

/**
 * One form for every account type: name, notes and bank details are shared;
 * an asset account also has a kind (chosen once, at creation), a currency
 * (locked once the account has transactions), an opening balance and its
 * other changes to the balance (AccountAdjustmentsField). Grouped as what
 * the account is and its balance (left on desktop), then its bank details
 * and, when editing, its history (right); one column on tablets and phones.
 */
export default function AccountForm({ type, title, description, account }: AccountFormProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { data: currencies } = useCurrencies()
  const currencyOptions = useCurrencyOptions()
  const createAccount = useCreateAccount(type)
  const updateAccount = useUpdateAccount()

  // A new account of money starts in the user's own currency.
  const { user } = useAuth()
  const defaultCurrency = type === 'asset' ? (user?.currency ?? '') : ''
  const [form, setForm] = useState<FormState>(initialState(account, defaultCurrency))
  const [isSubmitted, setIsSubmitted] = useState(false)

  // Once per account, not on every refetch: see useFormFill.
  useFormFill(account?.id ?? 'new', () => setForm(initialState(account, defaultCurrency)))

  const isEditing = !!account
  const isAsset = type === 'asset'
  const basePath = `/accounts/${type}`
  const isSaving = createAccount.isPending || updateAccount.isPending
  // Every amount on the account is in its currency: switching it later would change their meaning.
  const isCurrencyLocked = isEditing && account.lastActivityAt !== null
  const isAssetKindMissing = isAsset && !isEditing && !form.assetKind
  const isCurrencyMissing = isAsset && !form.currencyCode
  // A row of the other balance changes left open: saving would drop what was typed in it.
  const [isEditingAdjustment, setIsEditingAdjustment] = useState(false)

  const leave = () => (isEditing ? navigate(-1) : navigate(basePath))

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setIsSubmitted(true)

    if (isAssetKindMissing || isCurrencyMissing || isEditingAdjustment) {
      return
    }

    const payload = {
      name: form.name,
      iban: form.iban || null,
      bic: form.bic || null,
      accountNumber: form.accountNumber || null,
      notes: form.notes || null,
      isActive: form.isActive,
      ...(isAsset
        ? {
            currencyCode: form.currencyCode || null,
            openingBalance: form.openingBalance || '0.00',
            openingBalanceDate: form.openingBalanceDate || null,
            adjustments: form.adjustments.map((row) => ({
              ...(row.id ? { id: row.id } : {}),
              date: row.date,
              amount: row.amount,
              description: row.description.trim() || null,
            })),
          }
        : {}),
    }

    try {
      if (isEditing) {
        await updateAccount.mutateAsync({ id: account.id, data: payload })
      } else {
        await createAccount.mutateAsync({
          type,
          ...payload,
          ...(isAsset ? { assetKind: form.assetKind || null } : {}),
        })
      }

      leave()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const selectedCurrency = currencies?.find((currency) => currency.code === form.currencyCode)

  const kindChooser = isAsset && !isEditing && (
    <div className="space-y-2">
      <div className="grid gap-3 sm:grid-cols-2">
        {ASSET_KINDS.map((kind) => {
          const Icon = ASSET_KIND_ICONS[kind]
          const isSelected = form.assetKind === kind
          return (
            <button
              key={kind}
              type="button"
              aria-pressed={isSelected}
              onClick={() => setForm({ ...form, assetKind: kind })}
              className={cn(
                'flex items-center gap-3 rounded-xl border-2 bg-card p-4 text-left transition-colors hover:bg-accent/50',
                isSelected ? 'border-primary bg-primary/5' : 'border-border'
              )}
            >
              <Icon className={cn('h-8 w-8 shrink-0', isSelected ? 'text-primary' : 'text-muted-foreground')} />
              <div>
                <div className="font-semibold">{t(`accountsCommon.assetKind_${kind}`)}</div>
                <div className="text-xs text-muted-foreground">{t(`accountsCommon.assetKindDescription_${kind}`)}</div>
              </div>
            </button>
          )
        })}
      </div>
      {isSubmitted && isAssetKindMissing ? (
        <p className="text-xs text-destructive">{t('accountsCommon.assetKindRequired')}</p>
      ) : (
        <p className="text-xs text-muted-foreground">{t('accountsCommon.assetKindImmutableHint')}</p>
      )}
    </div>
  )

  const currencyField = (
    <div className="space-y-2">
      <Label htmlFor="currencyCode">{t('accountsCommon.currency')}</Label>
      {isCurrencyLocked ? (
        <>
          <div id="currencyCode" className="flex h-10 items-center text-sm">
            {selectedCurrency ? currencyLabel(selectedCurrency, i18n.language) : form.currencyCode}
          </div>
          <p className="text-xs text-muted-foreground">{t('accountsCommon.currencyLockedHint')}</p>
        </>
      ) : (
        <>
          <Combobox
            options={currencyOptions}
            value={form.currencyCode}
            onChange={(value) => setForm({ ...form, currencyCode: value })}
            placeholder={t('accountsCommon.selectCurrency')}
          />
          {isSubmitted && isCurrencyMissing && (
            <p className="text-xs text-destructive">{t('accountsCommon.currencyRequired')}</p>
          )}
        </>
      )}
    </div>
  )

  return (
    <div className="space-y-6">
      <PageHeader title={title} description={description} />

      <form onSubmit={handleSubmit} className="space-y-6">
        {kindChooser}

        {/* Desktop: what the account is and its balance on the left, the details on the right. */}
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="space-y-6">
            <FormSection
              title={t('accountsCommon.sectionGeneral')}
              aside={
                isAsset &&
                isEditing &&
                form.assetKind && (
                  <Badge variant="secondary" className="font-normal">
                    {t(`accountsCommon.assetKind_${form.assetKind}`)}
                  </Badge>
                )
              }
              contentClassName="grid gap-4 sm:grid-cols-2"
            >
                <div className={cn('space-y-2', !isAsset && 'sm:col-span-2')}>
                  <Label htmlFor="name">{t('accountsCommon.name')}</Label>
                  <Input id="name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                </div>

                {isAsset && currencyField}

                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="notes">{t('accountsCommon.notes')}</Label>
                  <textarea
                    id="notes"
                    rows={2}
                    className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    value={form.notes}
                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  />
                </div>

                {isEditing && (
                  <div className="space-y-1 sm:col-span-2">
                    <div className="flex items-center gap-2">
                      <input
                        id="isActive"
                        type="checkbox"
                        className="h-4 w-4 rounded border-input"
                        checked={form.isActive}
                        onChange={(e) => setForm({ ...form, isActive: e.target.checked })}
                      />
                      <Label htmlFor="isActive">{t('accountsCommon.active')}</Label>
                    </div>
                    <p className="text-xs text-muted-foreground">{t('accountsCommon.activeHint')}</p>
                  </div>
                )}
            </FormSection>

            {isAsset && (
              <FormSection title={t('accountsCommon.sectionBalance')} contentClassName="space-y-6">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                      <Label htmlFor="openingBalance">{t('accountsCommon.openingBalance')}</Label>
                      <Input
                        id="openingBalance"
                        type="number"
                        step="0.01"
                        value={form.openingBalance}
                        onChange={(e) => setForm({ ...form, openingBalance: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="openingBalanceDate">{t('accountsCommon.openingBalanceDate')}</Label>
                      <Input
                        id="openingBalanceDate"
                        type="date"
                        value={form.openingBalanceDate}
                        onChange={(e) => setForm({ ...form, openingBalanceDate: e.target.value })}
                      />
                    </div>
                    {form.assetKind === 'investment' && (
                      <p className="text-xs text-muted-foreground sm:col-span-2">
                        {t('accountsCommon.openingBalanceInvestmentHint')}
                      </p>
                    )}
                  </div>

                  <AccountAdjustmentsField
                    rows={form.adjustments}
                    onChange={(adjustments) => setForm({ ...form, adjustments })}
                    currencySymbol={selectedCurrency?.symbol ?? null}
                    onEditingChange={setIsEditingAdjustment}
                    showOpenRowError={isSubmitted}
                  />
              </FormSection>
            )}
          </div>

          <div className="space-y-6">
            {/* One column beside the main one on desktop, three across on a tablet, stacked on a phone. */}
            <FormSection
              title={t('accountsCommon.sectionBankDetails')}
              optional
              contentClassName="grid gap-4 sm:grid-cols-3 lg:grid-cols-1"
            >
                <div className="space-y-2">
                  <Label htmlFor="iban">{t('accountsCommon.iban')}</Label>
                  <Input id="iban" value={form.iban} onChange={(e) => setForm({ ...form, iban: e.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="bic">{t('accountsCommon.bic')}</Label>
                  <Input id="bic" value={form.bic} onChange={(e) => setForm({ ...form, bic: e.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="accountNumber">{t('accountsCommon.accountNumber')}</Label>
                  <Input
                    id="accountNumber"
                    value={form.accountNumber}
                    onChange={(e) => setForm({ ...form, accountNumber: e.target.value })}
                  />
                </div>
            </FormSection>

            {isEditing && <AccountHistoryCard accountId={account.id} currencySymbol={account.currencySymbol} flush />}
          </div>
        </div>

        <FormActions>
          <Button type="button" variant="outline" onClick={leave}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={isSaving}>
            {t('common.save')}
          </Button>
        </FormActions>
      </form>
    </div>
  )
}
