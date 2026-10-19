import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { toast } from 'sonner'
import App from './App'
import './index.css'
import './i18n/config'
import { applyTheme, getTheme } from './lib/theme'
import { TooltipProvider } from './components/ui/tooltip'
import { handleApiError } from './lib/error-handler'

// Initialize theme system
applyTheme(getTheme())

const queryClient = new QueryClient({
  // A list that failed to load would otherwise just look empty. Only when
  // there is nothing on screen yet (a failed background refetch keeps what is
  // shown), never for a 401 (api.ts refreshes or goes to the login), and not
  // for queries whose page shows the error itself (meta.quietError).
  queryCache: new QueryCache({
    onError: (error, query) => {
      if (query.state.data !== undefined || query.meta?.quietError) return
      if (error instanceof AxiosError && error.response?.status === 401) return
      toast.error(handleApiError(error), { id: 'query-error' })
    },
  }),
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      // Back to the tab, or to the installed app from the background: queries
      // older than staleTime refetch (edit forms keep what is typed, useFormFill).
      refetchOnWindowFocus: true,
      retry: 1,
    },
  },
})

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <TooltipProvider delayDuration={200}>
        <App />
      </TooltipProvider>
    </QueryClientProvider>
  </React.StrictMode>,
)
