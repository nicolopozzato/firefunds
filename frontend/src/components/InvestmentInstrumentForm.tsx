import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useCreateInvestmentInstrument, useUpdateInvestmentInstrument } from '@/hooks/use-investments'
import { useFormFill } from '@/hooks/use-form-fill'
import { handleApiError } from '@/lib/error-handler'
import { useAuth } from '@/contexts/AuthContext'
import { formatRate, rateFromWhiteListShare } from '@/lib/capital-gains-tax'
import type { InvestmentInstrument, InvestmentInstrumentType } from '@/types/investment'
import FormActions from '@/components/FormActions'

const INSTRUMENT_TYPES: InvestmentInstrumentType[] = ['etf', 'stock', 'bond', 'fund', 'cryptocurrency', 'other']

const basePath = '/investments/instruments'

interface InvestmentInstrumentFormProps {
  instrument?: InvestmentInstrument
}

interface FormState {
  name: string
  type: InvestmentInstrumentType | ''
  ticker: string
  yahooTicker: string
  isin: string
  capitalGainsTaxRate: string
}

function initialState(instrument?: InvestmentInstrument): FormState {
  return {
    name: instrument?.name ?? '',
    type: instrument?.type ?? '',
    ticker: instrument?.ticker ?? '',
    yahooTicker: instrument?.yahooTicker ?? '',
    isin: instrument?.isin ?? '',
    capitalGainsTaxRate: instrument?.capitalGainsTaxRate != null ? String(Number(instrument.capitalGainsTaxRate)) : '',
  }
}

export default function InvestmentInstrumentForm({ instrument }: InvestmentInstrumentFormProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const createInstrument = useCreateInvestmentInstrument()
  const updateInstrument = useUpdateInvestmentInstrument()

  const [form, setForm] = useState<FormState>(initialState(instrument))
  const { user } = useAuth()
  const userRate = Number(user?.capitalGainsTaxRate ?? '26')
  // Only an input aid: typing the white-list share fills in the rate (the share itself isn't saved).
  const [whiteListShare, setWhiteListShare] = useState('')

  const handleWhiteListShareChange = (value: string) => {
    setWhiteListShare(value)
    if (value === '' || Number.isNaN(Number(value))) return
    const share = Math.min(100, Math.max(0, Number(value)))
    setForm((current) => ({ ...current, capitalGainsTaxRate: String(Number(rateFromWhiteListShare(userRate, share))) }))
  }

  const handleRateChange = (value: string) => {
    setForm((current) => ({ ...current, capitalGainsTaxRate: value }))
    setWhiteListShare('') // a rate typed by hand no longer matches the share
  }

  // Once per instrument, not on every refetch: see useFormFill.
  useFormFill(instrument?.id ?? 'new', () => setForm(initialState(instrument)))

  const isEditing = !!instrument
  const isSaving = createInstrument.isPending || updateInstrument.isPending

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()

    if (!form.type) return

    const payload = {
      name: form.name,
      type: form.type,
      ticker: form.ticker || null,
      yahooTicker: form.yahooTicker || null,
      isin: form.isin || null,
      capitalGainsTaxRate: form.capitalGainsTaxRate || null,
    }

    try {
      if (isEditing) {
        await updateInstrument.mutateAsync({ id: instrument.id, data: payload })
      } else {
        await createInstrument.mutateAsync(payload)
      }

      navigate(basePath)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t(isEditing ? 'investmentInstruments.editTitle' : 'investmentInstruments.add')}
        description={t('investmentInstruments.description')}
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">{t('investmentInstruments.sectionGeneral')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2 sm:col-span-2">
              <Label htmlFor="name">{t('investmentInstruments.name')}</Label>
              <Input
                id="name"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="type">{t('investmentInstruments.type')}</Label>
              <Select
                value={form.type}
                onValueChange={(value) => setForm({ ...form, type: value as InvestmentInstrumentType })}
              >
                <SelectTrigger id="type">
                  <SelectValue placeholder={t('investmentInstruments.selectType')} />
                </SelectTrigger>
                <SelectContent>
                  {INSTRUMENT_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {t(`investmentInstruments.type_${type}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t('investmentInstruments.sectionCodes')}
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                {t('investmentInstruments.sectionCodesOptional')}
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="ticker">{t('investmentInstruments.ticker')}</Label>
              <Input
                id="ticker"
                value={form.ticker}
                onChange={(e) => setForm({ ...form, ticker: e.target.value.toUpperCase() })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="isin">{t('investmentInstruments.isin')}</Label>
              <Input
                id="isin"
                className="font-mono"
                value={form.isin}
                onChange={(e) => setForm({ ...form, isin: e.target.value.toUpperCase() })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="yahooTicker">{t('investmentInstruments.yahooTicker')}</Label>
              <Input
                id="yahooTicker"
                placeholder={t('investmentInstruments.yahooTickerPlaceholder')}
                value={form.yahooTicker}
                onChange={(e) => setForm({ ...form, yahooTicker: e.target.value.toUpperCase() })}
              />
              <p className="text-xs text-muted-foreground">{t('investmentInstruments.yahooTickerHint')}</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t('investmentInstruments.sectionTax')}
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                {t('investmentInstruments.sectionTaxOptional')}
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="capitalGainsTaxRate">{t('investmentInstruments.capitalGainsTaxRate')}</Label>
              <Input
                id="capitalGainsTaxRate"
                type="number"
                step="0.01"
                min="0"
                max="100"
                className="tabular-nums"
                placeholder={t('investmentInstruments.capitalGainsTaxRatePlaceholder', { rate: formatRate(userRate) })}
                value={form.capitalGainsTaxRate}
                onChange={(e) => handleRateChange(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t('investmentInstruments.capitalGainsTaxRateHint', { rate: formatRate(userRate) })}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="whiteListShare">{t('investmentInstruments.whiteListShare')}</Label>
              <Input
                id="whiteListShare"
                type="number"
                step="0.01"
                min="0"
                max="100"
                className="tabular-nums"
                placeholder="78,08"
                value={whiteListShare}
                onChange={(e) => handleWhiteListShareChange(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">{t('investmentInstruments.whiteListShareHint')}</p>
            </div>
          </CardContent>
        </Card>

        <FormActions>
          <Button type="button" variant="outline" onClick={() => navigate(basePath)}>
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
