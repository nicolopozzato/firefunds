import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Link } from 'react-router-dom'
import { Copy, Download, KeyRound, Plus, Trash2 } from 'lucide-react'
import ConfirmDialog from '@/components/ConfirmDialog'
import Field from '@/components/Field'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import SegmentedControl from '@/components/ui/segmented-control'
import { useApiTokens, useCreateApiToken, useRevokeApiToken } from '@/hooks/use-drafts'
import { handleApiError } from '@/lib/error-handler'
import { formatShortDate } from '@/lib/utils'
import type { ApiToken, ApiTokenScope } from '@/types/draft'

/**
 * Preferences → API: personal access tokens for other programs (a phone
 * reading the bank's notifications, a script), each with what it may do. A
 * new token is shown once, then only its first characters.
 */
export default function ApiTokensPreferences() {
  const { t } = useTranslation()
  const { data: tokens } = useApiTokens()
  const create = useCreateApiToken()
  const revoke = useRevokeApiToken()
  const [name, setName] = useState('')
  const [scope, setScope] = useState<ApiTokenScope>('drafts')
  const [made, setMade] = useState<ApiToken | null>(null)
  const [revoking, setRevoking] = useState<ApiToken | null>(null)

  const submit = async () => {
    try {
      setMade(await create.mutateAsync({ name: name.trim(), scope }))
      setName('')
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const copy = async (text: string) => {
    await navigator.clipboard.writeText(text)
    toast.success(t('apiTokens.copied'))
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <Card>
        <CardHeader className="pb-2 sm:pb-2">
          <CardTitle>{t('androidApp.title')}</CardTitle>
          <CardDescription>{t('androidApp.description')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <ol className="list-decimal space-y-1.5 pl-5 text-sm">
            {['step1', 'step2', 'step3', 'step4', 'step5'].map((step) => (
              <li key={step}>{t(`androidApp.${step}`)}</li>
            ))}
          </ol>
          <div className="flex flex-wrap gap-2">
            <Button asChild>
              <a href="/downloads/firefunds.apk" download>
                <Download className="mr-2 h-4 w-4" />
                {t('androidApp.download')}
              </a>
            </Button>
            <Button variant="outline" asChild>
              <Link to="/sources">{t('androidApp.sources')}</Link>
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-2 sm:pb-2">
          <CardTitle>{t('apiTokens.new')}</CardTitle>
          <CardDescription>{t('apiTokens.description')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('apiTokens.name')} htmlFor="tokenName" hint={t('apiTokens.nameHint')}>
              <Input id="tokenName" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />
            </Field>
            <Field label={t('apiTokens.scope')} hint={t(`apiTokens.scopeHint_${scope}`)}>
              <SegmentedControl
                label={t('apiTokens.scope')}
                value={scope}
                onChange={setScope}
                options={[
                  { value: 'drafts', label: t('apiTokens.scope_drafts') },
                  { value: 'full', label: t('apiTokens.scope_full') },
                ]}
              />
            </Field>
          </div>
          <Button disabled={!name.trim() || create.isPending} onClick={() => void submit()}>
            <Plus className="mr-2 h-4 w-4" />
            {t('apiTokens.create')}
          </Button>

          {made?.token && (
            <div className="space-y-2 rounded-lg border border-positive/40 bg-positive/5 p-3">
              <p className="text-sm font-medium">{t('apiTokens.madeTitle', { name: made.name })}</p>
              <p className="text-xs text-muted-foreground">{t('apiTokens.madeHint')}</p>
              <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 font-mono text-xs select-all">{made.token}</code>
                <Button variant="outline" size="icon" aria-label={t('apiTokens.copy')} title={t('apiTokens.copy')} onClick={() => void copy(made.token!)}>
                  <Copy className="h-4 w-4" />
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">{t('apiTokens.usage')}</p>
              <code className="block overflow-x-auto whitespace-pre rounded bg-muted px-2 py-1.5 font-mono text-xs">
                {`curl -H "Authorization: Bearer ${made.token.slice(0, 10)}…" \\\n  ${window.location.origin}/api/accounts/asset`}
              </code>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-2 sm:pb-2">
          <CardTitle>{t('apiTokens.list')}</CardTitle>
        </CardHeader>
        <CardContent>
          {tokens && tokens.length > 0 ? (
            <ul className="divide-y">
              {tokens.map((token) => (
                <li key={token.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                  <KeyRound className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 text-sm font-medium">
                      <span className="truncate">{token.name}</span>
                      <Badge variant={token.scope === 'full' ? 'warning' : 'info'} className="shrink-0 font-normal">
                        {t(`apiTokens.scope_${token.scope}`)}
                      </Badge>
                    </div>
                    <div className="text-xs text-muted-foreground">
                      <span className="font-mono">{token.hint}…</span> · {t('apiTokens.createdOn', { date: formatShortDate(token.createdAt) })}
                      {' · '}
                      {token.lastUsedAt ? t('apiTokens.lastUsed', { date: formatShortDate(token.lastUsedAt) }) : t('apiTokens.neverUsed')}
                    </div>
                  </div>
                  <Button variant="ghost" size="icon" aria-label={t('apiTokens.revoke')} title={t('apiTokens.revoke')} onClick={() => setRevoking(token)}>
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">{t('apiTokens.none')}</p>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={!!revoking}
        onOpenChange={(open) => !open && setRevoking(null)}
        title={t('apiTokens.revokeTitle', { name: revoking?.name })}
        description={t('apiTokens.revokeDescription')}
        confirmLabel={t('apiTokens.revoke')}
        pending={revoke.isPending}
        onConfirm={() => revoking && revoke.mutate(revoking.id, { onSuccess: () => setRevoking(null) })}
      />
    </div>
  )
}
