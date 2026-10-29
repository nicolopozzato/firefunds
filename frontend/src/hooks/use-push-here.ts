import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { usePushDevices } from '@/hooks/use-notifications'
import { api } from '@/lib/api'
import { handleApiError } from '@/lib/error-handler'
import { currentSubscription, disablePush, enablePush, pushSupport } from '@/lib/push'

/**
 * Push on this device: whether it can be, whether it is on (the browser is
 * subscribed and the server knows it), and turning it on or off. Turning it
 * on sends a test notification, so the user sees it works.
 */
export function usePushHere() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [support, setSupport] = useState(pushSupport)
  const [busy, setBusy] = useState(false)
  const { data: devices } = usePushDevices(support === 'ok')
  const { data: endpoint } = useQuery({
    queryKey: ['push-endpoint'],
    queryFn: async () => (await currentSubscription())?.endpoint ?? null,
    enabled: support === 'ok',
    staleTime: Infinity,
  })
  const device = devices?.find((d) => d.endpoint === endpoint) ?? null
  // Known only once both answered; until then nobody is invited to turn it on.
  const known = support !== 'ok' || (devices !== undefined && endpoint !== undefined)

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['push-endpoint'] }),
      queryClient.invalidateQueries({ queryKey: ['push-devices'] }),
    ])

  const enable = async () => {
    setBusy(true)
    try {
      const added = await enablePush()
      await refresh()
      try {
        await api.post(`/api/push/subscriptions/${added.id}/test`)
        toast.success(t('pushPrefs.enabled'), { description: t('pushPrefs.enabledTest') })
      } catch (error) {
        toast.warning(t('pushPrefs.enabledNoTest'), { description: handleApiError(error) })
      }
    } catch (error) {
      setSupport(pushSupport())
      toast.error(error instanceof Error && error.message === 'permission' ? t('pushPrefs.permissionRefused') : handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const disable = async () => {
    setBusy(true)
    try {
      await disablePush()
      await refresh()
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  return { support, device, on: device !== null, known, busy, enable, disable, refresh }
}
