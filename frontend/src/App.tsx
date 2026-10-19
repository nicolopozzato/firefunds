import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './contexts/AuthContext'
import { TimeframeProvider } from './contexts/TimeframeContext'
import { Toaster } from './components/ui/sonner'
import Layout from './components/layout/Layout'
import Dashboard from './pages/Dashboard'
import AssetAccounts from './pages/AssetAccounts'
import ExpenseAccounts from './pages/ExpenseAccounts'
import RevenueAccounts from './pages/RevenueAccounts'
import AccountFormPage from './pages/AccountFormPage'
import AllTransactions from './pages/AllTransactions'
import WithdrawalTransactions from './pages/WithdrawalTransactions'
import DepositTransactions from './pages/DepositTransactions'
import TransferTransactions from './pages/TransferTransactions'
import TransactionFormPage from './pages/TransactionFormPage'
import TransactionDetailPage from './pages/TransactionDetailPage'
import InvestmentTransactions from './pages/InvestmentTransactions'
import InvestmentTransactionFormPage from './pages/InvestmentTransactionFormPage'
import InvestmentTransactionDetailPage from './pages/InvestmentTransactionDetailPage'
import AccountDetailPage from './pages/AccountDetailPage'
import Categories from './pages/Categories'
import Tags from './pages/Tags'
import ClassificationFormPage from './pages/ClassificationFormPage'
import InvestmentsOverview from './pages/InvestmentsOverview'
import IncomeExpenseReport from './pages/reports/IncomeExpenseReport'
import NetWorthReport from './pages/reports/NetWorthReport'
import Search from './pages/Search'
import More from './pages/More'
import InvestmentInstruments from './pages/InvestmentInstruments'
import InvestmentInstrumentFormPage from './pages/InvestmentInstrumentFormPage'
import InvestmentInstrumentDetailPage from './pages/InvestmentInstrumentDetailPage'
import Profile from './pages/Profile'
import Preferences from './pages/Preferences'
import AdminSettings from './pages/admin/AdminSettings'
import AdminCurrencies from './pages/admin/AdminCurrencies'
import AdminUsers from './pages/admin/AdminUsers'
import Login from './pages/Login'
import Register from './pages/Register'
import { useApplyThemePreference } from './hooks/use-theme'

function PrivateRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, loading } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-foreground"></div>
      </div>
    )
  }

  return isAuthenticated ? <>{children}</> : <Navigate to="/login" replace />
}

function AdminRoute({ children }: { children: React.ReactNode }) {
  const { user } = useAuth()

  return user?.isAdmin ? <>{children}</> : <Navigate to="/dashboard" replace />
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route
        path="/*"
        element={
          <PrivateRoute>
            <Layout>
              <Routes>
                <Route path="/" element={<Navigate to="/dashboard" replace />} />
                <Route path="/dashboard" element={<Dashboard />} />
                <Route path="/accounts/asset" element={<AssetAccounts />} />
                <Route path="/accounts/asset/new" element={<AccountFormPage />} />
                <Route path="/accounts/asset/:id/edit" element={<AccountFormPage />} />
                <Route path="/accounts/asset/:id" element={<AccountDetailPage />} />
                <Route path="/accounts/expense" element={<ExpenseAccounts />} />
                <Route path="/accounts/expense/new" element={<AccountFormPage />} />
                <Route path="/accounts/expense/:id/edit" element={<AccountFormPage />} />
                <Route path="/accounts/expense/:id" element={<AccountDetailPage />} />
                <Route path="/accounts/revenue" element={<RevenueAccounts />} />
                <Route path="/accounts/revenue/new" element={<AccountFormPage />} />
                <Route path="/accounts/revenue/:id/edit" element={<AccountFormPage />} />
                <Route path="/accounts/revenue/:id" element={<AccountDetailPage />} />
                <Route path="/transactions" element={<AllTransactions />} />
                <Route path="/transactions/new" element={<TransactionFormPage />} />
                <Route path="/transactions/:id/edit" element={<TransactionFormPage />} />
                <Route path="/transactions/:id" element={<TransactionDetailPage />} />
                <Route path="/transactions/withdrawal" element={<WithdrawalTransactions />} />
                <Route path="/transactions/deposit" element={<DepositTransactions />} />
                <Route path="/transactions/transfers" element={<TransferTransactions />} />
                <Route path="/transactions/investments" element={<InvestmentTransactions />} />
                <Route path="/transactions/investments/new" element={<InvestmentTransactionFormPage />} />
                <Route path="/transactions/investments/:id/edit" element={<InvestmentTransactionFormPage />} />
                <Route path="/transactions/investments/:id" element={<InvestmentTransactionDetailPage />} />
                <Route path="/categories" element={<Categories />} />
                <Route path="/categories/new" element={<ClassificationFormPage />} />
                <Route path="/categories/:id/edit" element={<ClassificationFormPage />} />
                <Route path="/tags" element={<Tags />} />
                <Route path="/tags/new" element={<ClassificationFormPage />} />
                <Route path="/tags/:id/edit" element={<ClassificationFormPage />} />
                <Route path="/investments/overview" element={<InvestmentsOverview />} />
                <Route path="/investments/instruments" element={<InvestmentInstruments />} />
                <Route path="/investments/instruments/new" element={<InvestmentInstrumentFormPage />} />
                <Route path="/investments/instruments/:id/edit" element={<InvestmentInstrumentFormPage />} />
                <Route path="/investments/instruments/:id" element={<InvestmentInstrumentDetailPage />} />
                <Route path="/reports" element={<Navigate to="/reports/income-expenses" replace />} />
                <Route path="/reports/income-expenses" element={<IncomeExpenseReport />} />
                <Route path="/reports/net-worth" element={<NetWorthReport />} />
                <Route path="/search" element={<Search />} />
                <Route path="/more" element={<More />} />
                <Route path="/profile" element={<Profile />} />
                <Route path="/preferences" element={<Preferences />} />
                <Route
                  path="/admin/settings"
                  element={
                    <AdminRoute>
                      <AdminSettings />
                    </AdminRoute>
                  }
                />
                <Route
                  path="/admin/currencies"
                  element={
                    <AdminRoute>
                      <AdminCurrencies />
                    </AdminRoute>
                  }
                />
                <Route
                  path="/admin/users"
                  element={
                    <AdminRoute>
                      <AdminUsers />
                    </AdminRoute>
                  }
                />
              </Routes>
            </Layout>
          </PrivateRoute>
        }
      />
    </Routes>
  )
}

/** Keeps the theme on the user's preference (needs the signed-in user). */
function ThemeSync() {
  useApplyThemePreference()
  return null
}

function App() {
  return (
    <Router>
      <AuthProvider>
        <ThemeSync />
        <TimeframeProvider>
          <AppRoutes />
          <Toaster richColors closeButton />
        </TimeframeProvider>
      </AuthProvider>
    </Router>
  )
}

export default App
