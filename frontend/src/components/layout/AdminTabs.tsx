import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import SegmentedControl from '@/components/ui/segmented-control'

/** The administration's pages, at the top of each of them (the header menu is the other way in). */
export default function AdminTabs() {
  const { t } = useTranslation()
  const location = useLocation()
  const navigate = useNavigate()
  const links = [
    { value: '/admin/settings', label: t('admin.settings.title') },
    { value: '/admin/backups', label: t('admin.backups.title') },
    { value: '/admin/tasks', label: t('admin.tasks.title') },
    { value: '/admin/users', label: t('admin.users.title') },
  ]
  const current = links.find((link) => link.value === location.pathname)?.value ?? links[0].value

  return (
    <SegmentedControl
      label={t('admin.title')}
      options={links}
      value={current}
      onChange={(href) => navigate(href, { replace: true })}
      scrollMemoryKey="admin"
    />
  )
}
