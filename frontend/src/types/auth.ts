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
  /** Percentage used to estimate the tax on capital gains if sold today (Italy: 26). */
  capitalGainsTaxRate: string;
}

export interface UpdateProfileRequest {
  firstName: string
  lastName: string
  email: string
}

export interface ChangePasswordRequest {
  currentPassword: string
  newPassword: string
}

export interface UpdatePreferencesRequest {
  locale: string
  theme: UserTheme
  pageSize: number
  timezone: string
  numberFormat: NumberFormat
  capitalGainsTaxRate?: string
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
