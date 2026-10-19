import { Toaster as Sonner } from 'sonner'
import { useIsMobile } from '@/hooks/use-media-query'
import { useThemeMode } from '@/hooks/use-theme'

type ToasterProps = React.ComponentProps<typeof Sonner>

const Toaster = ({ ...props }: ToasterProps) => {
  // Phones: on top, under the notch, out of the way of the bottom navigation bar.
  const isMobile = useIsMobile()
  // The app's mode, not the device's: they differ when the user picked one.
  const mode = useThemeMode()
  return (
    <Sonner
      position={isMobile ? 'top-center' : 'top-right'}
      mobileOffset={{ top: 'calc(env(safe-area-inset-top) + 8px)' }}
      theme={mode}
      className="toaster group"
      toastOptions={{
        classNames: {
          toast:
            'group toast group-[.toaster]:bg-background group-[.toaster]:text-foreground group-[.toaster]:border-border group-[.toaster]:shadow-lg',
          description: 'group-[.toast]:text-muted-foreground',
          actionButton: 'group-[.toast]:bg-primary group-[.toast]:text-primary-foreground',
          cancelButton: 'group-[.toast]:bg-muted group-[.toast]:text-muted-foreground',
          error: 'group-[.toaster]:!bg-red-50 group-[.toaster]:!text-red-900 group-[.toaster]:!border-red-200 dark:group-[.toaster]:!bg-red-950 dark:group-[.toaster]:!text-red-200 dark:group-[.toaster]:!border-red-900',
          success: 'group-[.toaster]:!bg-green-50 group-[.toaster]:!text-green-900 group-[.toaster]:!border-green-200 dark:group-[.toaster]:!bg-green-950 dark:group-[.toaster]:!text-green-200 dark:group-[.toaster]:!border-green-900',
          warning: 'group-[.toaster]:!bg-amber-50 group-[.toaster]:!text-amber-900 group-[.toaster]:!border-amber-200 dark:group-[.toaster]:!bg-amber-950 dark:group-[.toaster]:!text-amber-200 dark:group-[.toaster]:!border-amber-900',
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
