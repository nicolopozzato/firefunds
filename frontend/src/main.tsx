import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App'
import './index.css'
import './i18n/config'
import { applyTheme, getTheme } from './lib/theme'
import { TooltipProvider } from './components/ui/tooltip'

// Initialize theme system
applyTheme(getTheme())

// No pinch zoom, as in a native app: Safari on iOS ignores user-scalable=no in
// the viewport (index.html), but lets its own gesture event be cancelled.
document.addEventListener('gesturestart', (event) => event.preventDefault())

const queryClient = new QueryClient({
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
