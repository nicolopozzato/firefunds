import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { authApi } from '@/lib/auth-api';
import { handleApiError } from '@/lib/error-handler';
import { LanguageSelector } from '@/components/LanguageSelector';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

// What the server takes in one request (Caddyfile, 10-app.ini).
const MAX_BYTES = 20 * 1024 * 1024;

/**
 * A new installation started from a backup instead of a first account
 * (offered by the login page while there are no users). Once somebody exists
 * it leads back to the login: from then on a backup is restored from the
 * console only.
 */
export default function RestoreBackup() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState('');
  const [restoring, setRestoring] = useState(false);

  useEffect(() => {
    authApi
      .checkRegistration()
      .then((data) => {
        if (data.hasUsers) navigate('/login', { replace: true });
      })
      .catch(() => {
        // The upload says what is wrong.
      });
  }, [navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;
    if (file.size > MAX_BYTES) {
      setError(t('auth.restoreTooLarge'));
      return;
    }

    setError('');
    setRestoring(true);
    try {
      await authApi.restoreFirstStart(file);
      toast.success(t('auth.restored'));
      navigate('/login', { replace: true });
    } catch (err) {
      setError(handleApiError(err));
      setRestoring(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <div className="absolute top-4 right-4">
        <LanguageSelector />
      </div>
      <Card className="w-full max-w-md shadow-lg">
        <CardHeader className="space-y-1">
          <CardTitle className="page-title text-center">{t('auth.restoreBackup')}</CardTitle>
          <CardDescription className="text-center">{t('auth.restoreDescription')}</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && (
              <div className="p-3 text-sm text-negative bg-negative/10 border border-negative/30 rounded-md">
                {error}
              </div>
            )}

            <p className="text-sm text-muted-foreground">{t('auth.restoreInfo')}</p>

            <div className="space-y-2">
              <Label htmlFor="backup">{t('auth.restoreFile')}</Label>
              <Input
                id="backup"
                type="file"
                accept=".tar,application/x-tar"
                disabled={restoring}
                onChange={(e) => {
                  setFile(e.target.files?.[0] ?? null);
                  setError('');
                }}
                required
              />
            </div>

            <Button type="submit" className="w-full" disabled={!file || restoring}>
              {restoring ? t('auth.restoring') : t('auth.restoreBackup')}
            </Button>
            {restoring && <p className="text-center text-sm text-muted-foreground">{t('auth.restoringHint')}</p>}

            {!restoring && (
              <div className="text-center text-sm text-muted-foreground">
                <Link to="/login" className="underline hover:text-foreground">
                  {t('auth.backToLogin')}
                </Link>
              </div>
            )}
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
