import { useTranslation } from 'react-i18next'
import PageHeader from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import DateWithTime from '@/components/DateWithTime'
import { useAdminUsers } from '@/hooks/use-admin'

export default function AdminUsers() {
  const { t } = useTranslation()
  const { data: users, isLoading } = useAdminUsers()

  return (
    <div className="space-y-6">
      <PageHeader title={t('admin.users.title')} description={t('admin.users.description')} />

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="py-12 text-center text-sm text-muted-foreground">{t('common.loading')}</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('admin.users.name')}</TableHead>
                  <TableHead>{t('common.email')}</TableHead>
                  <TableHead>{t('admin.users.role')}</TableHead>
                  <TableHead>{t('admin.users.createdAt')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {users?.map((user) => (
                  <TableRow key={user.id}>
                    <TableCell className="font-medium">
                      {user.firstName} {user.lastName}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{user.email}</TableCell>
                    <TableCell>
                      <Badge variant={user.isAdmin ? 'secondary' : 'outline'}>
                        {user.isAdmin ? t('common.admin') : t('admin.users.regularUser')}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      <DateWithTime date={user.createdAt} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
