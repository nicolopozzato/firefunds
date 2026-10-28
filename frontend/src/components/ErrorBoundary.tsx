import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import i18n from '@/i18n/config'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'

const RELOADED_KEY = 'firefunds-reloaded-for-chunk'

/** A page's code that no longer exists on the server: a new release came out since this tab loaded. */
function isStaleChunk(error: Error): boolean {
  return /dynamically imported module|Importing a module script failed|Loading chunk/i.test(error.message)
}

interface State {
  error: Error | null
}

/**
 * What a page that crashed shows instead of a blank screen. A page whose code
 * is gone after a release reloads the app once, which brings the new build.
 */
export default class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    if (isStaleChunk(error)) {
      try {
        if (!sessionStorage.getItem(RELOADED_KEY)) {
          sessionStorage.setItem(RELOADED_KEY, '1')
          window.location.reload()
          return
        }
      } catch {
        // no storage: show the error, the button reloads
      }
    }
    console.error(error, info.componentStack)
  }

  render(): ReactNode {
    if (this.state.error === null) {
      return this.props.children
    }

    return (
      <Card>
        <CardContent className="flex flex-col items-center gap-3 py-12 text-center sm:py-12">
          <AlertTriangle className="h-8 w-8 text-warning" aria-hidden />
          <p className="font-medium">{i18n.t('errors.pageCrashed')}</p>
          <Button onClick={() => window.location.reload()}>{i18n.t('common.reload')}</Button>
        </CardContent>
      </Card>
    )
  }
}
