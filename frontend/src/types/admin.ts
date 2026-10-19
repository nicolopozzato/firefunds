export interface ServerSettings {
  registrationEnabled: boolean
}

export interface AdminUser {
  id: string
  firstName: string
  lastName: string
  email: string
  isAdmin: boolean
  createdAt: string
}

export interface AdminCurrency {
  id: string
  code: string
  name: string
  symbol: string
  decimalPlaces: number
  isEnabled: boolean
}

export interface CurrencyRequest {
  code: string
  name: string
  symbol: string
  decimalPlaces: number
  isEnabled: boolean
}
