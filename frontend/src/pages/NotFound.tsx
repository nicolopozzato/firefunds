import { Compass } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'

/** An address that matches no page (a mistyped or outdated link). */
export default function NotFound() {
  const { t } = useTranslation()

  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-12 text-center sm:py-12">
        <Compass className="h-8 w-8 text-muted-foreground" aria-hidden />
        <p className="font-medium">{t('notFound.title')}</p>
        <p className="text-sm text-muted-foreground">{t('notFound.description')}</p>
        <Button asChild className="mt-2">
          <Link to="/dashboard">{t('notFound.back')}</Link>
        </Button>
      </CardContent>
    </Card>
  )
}
