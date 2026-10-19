import { AlertCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { handleApiError } from '@/lib/error-handler'

/**
 * What a page shows when its data couldn't be loaded (not found, server or
 * network error), instead of a skeleton that never ends. Its query is marked
 * `meta: { quietError: true }`, so the global error toast (main.tsx) stays out.
 */
export default function LoadFailed({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
        <AlertCircle className="h-8 w-8 text-negative" aria-hidden />
        <p className="font-medium">{t('errors.loadFailed')}</p>
        <p className="text-sm text-muted-foreground">{handleApiError(error)}</p>
        <div className="flex gap-2 pt-2">
          <Button variant="outline" onClick={() => navigate(-1)}>
            {t('common.back')}
          </Button>
          <Button onClick={onRetry}>{t('common.retry')}</Button>
        </div>
      </CardContent>
    </Card>
  )
}
