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
          // Tinted with the theme's meaning colours, over the page background (lib/theme.ts).
          error: 'group-[.toaster]:!bg-[color-mix(in_srgb,hsl(var(--negative))_12%,hsl(var(--background)))] group-[.toaster]:!text-foreground group-[.toaster]:!border-[hsl(var(--negative)/0.4)]',
          success: 'group-[.toaster]:!bg-[color-mix(in_srgb,hsl(var(--positive))_12%,hsl(var(--background)))] group-[.toaster]:!text-foreground group-[.toaster]:!border-[hsl(var(--positive)/0.4)]',
          warning: 'group-[.toaster]:!bg-[color-mix(in_srgb,hsl(var(--warning))_12%,hsl(var(--background)))] group-[.toaster]:!text-foreground group-[.toaster]:!border-[hsl(var(--warning)/0.4)]',
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
