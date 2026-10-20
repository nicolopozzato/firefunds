import { lazy, Suspense } from 'react'
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './contexts/AuthContext'
import { TimeframeProvider } from './contexts/TimeframeContext'
import { Toaster } from './components/ui/sonner'
import Layout from './components/layout/Layout'
import Login from './pages/Login'
import Register from './pages/Register'
import { useApplyThemePreference } from './hooks/use-theme'
import ErrorBoundary from './components/ErrorBoundary'
import PageLoading from './components/PageLoading'
import LoadFailed from './components/LoadFailed'
import NotFound from './pages/NotFound'

// Each page is its own chunk, loaded when first opened: the first load on a
// phone fetches only what it shows.
const Dashboard = lazy(() => import('./pages/Dashboard'))
const AssetAccounts = lazy(() => import('./pages/AssetAccounts'))
const ExpenseAccounts = lazy(() => import('./pages/ExpenseAccounts'))
const RevenueAccounts = lazy(() => import('./pages/RevenueAccounts'))
const AccountFormPage = lazy(() => import('./pages/AccountFormPage'))
const AllTransactions = lazy(() => import('./pages/AllTransactions'))
const WithdrawalTransactions = lazy(() => import('./pages/WithdrawalTransactions'))
const DepositTransactions = lazy(() => import('./pages/DepositTransactions'))
const TransferTransactions = lazy(() => import('./pages/TransferTransactions'))
const TransactionFormPage = lazy(() => import('./pages/TransactionFormPage'))
const TransactionDetailPage = lazy(() => import('./pages/TransactionDetailPage'))
const InvestmentTransactions = lazy(() => import('./pages/InvestmentTransactions'))
const InvestmentTransactionFormPage = lazy(() => import('./pages/InvestmentTransactionFormPage'))
const InvestmentTransactionDetailPage = lazy(() => import('./pages/InvestmentTransactionDetailPage'))
const AccountDetailPage = lazy(() => import('./pages/AccountDetailPage'))
const Categories = lazy(() => import('./pages/Categories'))
const Tags = lazy(() => import('./pages/Tags'))
const ClassificationFormPage = lazy(() => import('./pages/ClassificationFormPage'))
const InvestmentsOverview = lazy(() => import('./pages/InvestmentsOverview'))
const IncomeExpenseReport = lazy(() => import('./pages/reports/IncomeExpenseReport'))
const NetWorthReport = lazy(() => import('./pages/reports/NetWorthReport'))
const Search = lazy(() => import('./pages/Search'))
const More = lazy(() => import('./pages/More'))
const InvestmentInstruments = lazy(() => import('./pages/InvestmentInstruments'))
const InvestmentInstrumentFormPage = lazy(() => import('./pages/InvestmentInstrumentFormPage'))
const InvestmentInstrumentDetailPage = lazy(() => import('./pages/InvestmentInstrumentDetailPage'))
const Profile = lazy(() => import('./pages/Profile'))
const Preferences = lazy(() => import('./pages/Preferences'))
const AdminSettings = lazy(() => import('./pages/admin/AdminSettings'))
const AdminCurrencies = lazy(() => import('./pages/admin/AdminCurrencies'))
const AdminUsers = lazy(() => import('./pages/admin/AdminUsers'))

function PrivateRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, loading, unreachable, retry } = useAuth()

  if (unreachable) {
    return (
      <div className="mx-auto max-w-md px-4 py-16">
        <LoadFailed error={unreachable} onRetry={retry} canGoBack={false} />
      </div>
    )
  }

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

/** A page that failed to load or crashed shows an error, not a blank screen; moving to another page clears it. */
function PageBoundary({ children }: { children: React.ReactNode }) {
  const location = useLocation()

  return (
    <ErrorBoundary key={location.pathname}>
      <Suspense fallback={<PageLoading />}>{children}</Suspense>
    </ErrorBoundary>
  )
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
              <PageBoundary>
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
                <Route path="*" element={<NotFound />} />
              </Routes>
              </PageBoundary>
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
