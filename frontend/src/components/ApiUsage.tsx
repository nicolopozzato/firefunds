import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Copy, Download } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import SegmentedControl from '@/components/ui/segmented-control'
import { api } from '@/lib/api'
import { handleApiError } from '@/lib/error-handler'

type Recipe = 'curl' | 'shortcuts'

const FIELDS = ['amount', 'occurredAt', 'description', 'direction', 'timezone', 'sourceAccountId', 'destinationAccountId', 'notes', 'rawText', 'ifExists'] as const
const REQUIRED: readonly string[] = ['amount', 'occurredAt']

/** The JSON body of the example: what a bank's notification would become. */
const EXAMPLE_BODY = {
  amount: '12.50',
  occurredAt: '2026-10-28T12:30:00',
  description: 'Bar Centrale',
  direction: 'out',
  rawText: 'Pagamento di 12,50 EUR presso Bar Centrale',
}

function CodeBlock({ text, label, onCopy }: { text: string; label: string; onCopy: (text: string) => void }) {
  return (
    <div className="relative">
      <code className="block overflow-x-auto whitespace-pre rounded bg-muted px-2 py-1.5 pr-10 font-mono text-xs">{text}</code>
      <Button variant="ghost" size="icon" className="absolute right-0.5 top-0.5 h-7 w-7" aria-label={label} title={label} onClick={() => onCopy(text)}>
        <Copy className="h-3.5 w-3.5" />
      </Button>
    </div>
  )
}

/**
 * Preferences → API: how another program sends a draft with a token. The
 * address, a ready example (curl, or the steps in iOS Shortcuts), the fields,
 * and the OpenAPI description of the whole thing to download.
 */
export default function ApiUsage({ token }: { token?: string }) {
  const { t } = useTranslation()
  const [recipe, setRecipe] = useState<Recipe>('shortcuts')
  const origin = window.location.origin
  const url = `${origin}/api/drafts`
  const shown = token ?? 'ff_…'
  const body = JSON.stringify(EXAMPLE_BODY, null, 2)
  const curl = `curl -X POST ${url} \\\n  -H "Authorization: Bearer ${shown}" \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify(EXAMPLE_BODY)}'`

  const copy = async (text: string) => {
    await navigator.clipboard.writeText(text)
    toast.success(t('apiTokens.copied'))
  }

  // The description needs the user's login, so it can't be a plain link.
  const downloadSpec = async () => {
    try {
      const { data } = await api.get<unknown>('/api/openapi.json')
      const link = document.createElement('a')
      link.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
      link.download = 'firefunds-openapi.json'
      link.click()
      URL.revokeObjectURL(link.href)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{t('apiUsage.title')}</CardTitle>
        <CardDescription>{t('apiUsage.description')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="space-y-1.5">
          <p className="stat-label">{t('apiUsage.address')}</p>
          <CodeBlock text={url} label={t('apiTokens.copy')} onCopy={(text) => void copy(text)} />
          <p className="text-xs text-muted-foreground">{t('apiUsage.addressHint')}</p>
        </div>

        <div className="space-y-3">
          <SegmentedControl
            label={t('apiUsage.example')}
            value={recipe}
            onChange={setRecipe}
            options={[
              { value: 'shortcuts', label: t('apiUsage.shortcuts') },
              { value: 'curl', label: 'curl' },
            ]}
          />
          {recipe === 'curl' ? (
            <>
              <CodeBlock text={curl} label={t('apiTokens.copy')} onCopy={(text) => void copy(text)} />
              {!token && <p className="text-xs text-muted-foreground">{t('apiUsage.tokenHint')}</p>}
            </>
          ) : (
            <div className="space-y-3">
              <ol className="list-decimal space-y-1.5 pl-5 text-sm">
                {['step1', 'step2', 'step3', 'step4', 'step5', 'step6'].map((step) => (
                  <li key={step}>{t(`apiUsage.${step}`, { token: shown })}</li>
                ))}
              </ol>
              <p className="text-xs text-muted-foreground">{t('apiUsage.shortcutsBody')}</p>
              <CodeBlock text={body} label={t('apiTokens.copy')} onCopy={(text) => void copy(text)} />
              <p className="text-xs text-muted-foreground">{t('apiUsage.iosNotifications')}</p>
            </div>
          )}
        </div>

        <div className="space-y-2">
          <p className="stat-label">{t('apiUsage.fields')}</p>
          <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[11rem_1fr]">
            {FIELDS.map((field) => (
              <div key={field} className="contents">
                <dt className="font-mono text-xs sm:pt-0.5">
                  {field}
                  {REQUIRED.includes(field) && <span className="text-negative"> *</span>}
                </dt>
                <dd className="text-muted-foreground">{t(`apiUsage.field_${field}`)}</dd>
              </div>
            ))}
          </dl>
          <p className="text-xs text-muted-foreground">{t('apiUsage.ifExistsHint')}</p>
        </div>

        <div className="flex flex-wrap items-center gap-3 border-t pt-4">
          <Button variant="outline" onClick={() => void downloadSpec()}>
            <Download className="mr-2 h-4 w-4" />
            {t('apiUsage.downloadSpec')}
          </Button>
          <p className="min-w-0 flex-1 text-xs text-muted-foreground">{t('apiUsage.specHint')}</p>
        </div>
      </CardContent>
    </Card>
  )
}
