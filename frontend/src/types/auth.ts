export type UserTheme = 'light' | 'dark' | 'system'

/** BCP 47 tag used only for number separators: 1.234,56 / 1,234.56 / 1'234.56 / 1 234,56 */
export type NumberFormat = 'it-IT' | 'en-US' | 'de-CH' | 'fr-FR'

export interface User {
  id: string;
  firstName: string;
  lastName: string;
  email: string;
  roles: string[];
  isAdmin: boolean;
  locale: string;
  theme: UserTheme;
  pageSize: number;
  timezone: string;
  numberFormat: NumberFormat;
  /** ISO 4217 code of the currency shown where different currencies are added up. */
  currency: string;
  /** Percentage used to estimate the tax on capital gains if sold today (Italy: 26). */
  capitalGainsTaxRate: string;
  /** An administrator set the password: a new one is asked for before anything else. */
  mustChangePassword: boolean;
  /** The primary colour (lib/theme.ts). */
  colorScheme: string;
  density: string;
  startPage: StartPage;
  startPeriod: StartPeriod;
  /** The asset account a new transaction starts from; null when none (or no longer usable). */
  defaultAccountId: string | null;
  /** Every dashboard section, in order, each shown or not. */
  dashboardSections: DashboardSection[];
  dashboardTrendView: 'accounts' | 'investments';
  dashboardInvestmentUnit: 'percent' | 'money';
}

export type StartPage = 'dashboard' | 'transactions' | 'investments' | 'reports'

/** A rolling preset of the period picker, or the period chosen last time on this device. */
export type StartPeriod = 'last' | 'currentMonth' | 'last30Days' | 'last60Days' | 'currentYear'

export type DashboardSectionKey = 'summary' | 'trend' | 'expensesByCategory' | 'expenseAccounts' | 'revenueAccounts' | 'accounts'

export interface DashboardSection {
  key: DashboardSectionKey
  visible: boolean
}

export interface UpdateProfileRequest {
  firstName: string
  lastName: string
  email: string
  /** Needed only when the email changes. */
  currentPassword?: string
}

export interface ChangePasswordRequest {
  /** Not asked when the password must be changed (`User.mustChangePassword`). */
  currentPassword?: string
  newPassword: string
}

/** Every preference is optional: left out = unchanged. */
export interface UpdatePreferencesRequest {
  locale?: string
  theme?: UserTheme
  pageSize?: number
  timezone?: string
  numberFormat?: NumberFormat
  capitalGainsTaxRate?: string
  currency?: string
  colorScheme?: string
  density?: string
  startPage?: StartPage
  startPeriod?: StartPeriod
  /** "" = none. */
  defaultAccountId?: string
  dashboardSections?: DashboardSection[]
  dashboardTrendView?: User['dashboardTrendView']
  dashboardInvestmentUnit?: User['dashboardInvestmentUnit']
}

export const getFullName = (user: User): string => {
  return `${user.firstName} ${user.lastName}`;
};

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  refresh_token: string;
}

export interface RegisterRequest {
  firstName: string;
  lastName: string;
  email: string;
  password: string;
}

export interface RegisterResponse {
  message: string;
  user: User;
  isFirstUser: boolean;
}

export interface CheckRegistrationResponse {
  registrationAllowed: boolean;
  hasUsers: boolean;
}
