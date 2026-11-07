import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import Field from '@/components/Field'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useRenameApiToken } from '@/hooks/use-drafts'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'
import type { ApiToken } from '@/types/draft'

interface ApiTokenRenameDialogProps {
  /** The token to rename; null: closed. */
  token: ApiToken | null
  onClose: () => void
}

/** A token's name, changed: a dialog, a panel from the bottom on phones. The token keeps working. */
export default function ApiTokenRenameDialog({ token, onClose }: ApiTokenRenameDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const title = t('apiTokens.renameTitle', { name: token?.name })
  const body = token && <RenameForm key={token.id} token={token} onDone={onClose} />

  if (isMobile) {
    return (
      <Drawer open={!!token} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription className="sr-only">{t('apiTokens.nameHint')}</DrawerDescription>
          </DrawerHeader>
          <div className="px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{body}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={!!token} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{t('apiTokens.nameHint')}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  )
}

function RenameForm({ token, onDone }: { token: ApiToken; onDone: () => void }) {
  const { t } = useTranslation()
  const rename = useRenameApiToken()
  const [name, setName] = useState(token.name)

  const save = async (event: FormEvent) => {
    event.preventDefault()
    try {
      await rename.mutateAsync({ id: token.id, name: name.trim() })
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <form onSubmit={(event) => void save(event)} className="space-y-4">
      <Field label={t('apiTokens.name')} htmlFor="renameToken" hint={t('apiTokens.nameHint')}>
        <Input id="renameToken" value={name} maxLength={100} autoFocus onChange={(e) => setName(e.target.value)} />
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={!name.trim() || name.trim() === token.name || rename.isPending}>
          {t('common.save')}
        </Button>
      </div>
    </form>
  )
}
