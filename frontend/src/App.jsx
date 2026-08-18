import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import Layout from './components/Layout/Layout'
import LoginPage from './pages/LoginPage'

// Route-based code splitting — per CLAUDE.md performance requirements
const DashboardPage         = lazy(() => import('./pages/DashboardPage'))
const UnitCategoriesPage    = lazy(() => import('./pages/inventory/UnitCategoriesPage'))
const UnitTypesPage         = lazy(() => import('./pages/inventory/UnitTypesPage'))
const UnitConversionsPage   = lazy(() => import('./pages/inventory/UnitConversionsPage'))
const ProductsPage             = lazy(() => import('./pages/inventory/ProductsPage'))
const ProductFormPage          = lazy(() => import('./pages/inventory/ProductFormPage'))
const ProductViewPage          = lazy(() => import('./pages/inventory/ProductViewPage'))
const SalesChannelsPage        = lazy(() => import('./pages/inventory/SalesChannelsPage'))
const SalesChannelViewPage     = lazy(() => import('./pages/inventory/SalesChannelViewPage'))
const IndustriesPage           = lazy(() => import('./pages/inventory/IndustriesPage'))
const IndustryViewPage         = lazy(() => import('./pages/inventory/IndustryViewPage'))
const CompaniesPage            = lazy(() => import('./pages/inventory/CompaniesPage'))
const CompanyFormPage          = lazy(() => import('./pages/inventory/CompanyFormPage'))
const CompanyViewPage          = lazy(() => import('./pages/inventory/CompanyViewPage'))
const LocationsPage            = lazy(() => import('./pages/inventory/LocationsPage'))
const LocationFormPage         = lazy(() => import('./pages/inventory/LocationFormPage'))
const LocationViewPage         = lazy(() => import('./pages/inventory/LocationViewPage'))
const SuppliersPage            = lazy(() => import('./pages/inventory/SuppliersPage'))
const SupplierFormPage         = lazy(() => import('./pages/inventory/SupplierFormPage'))
const SupplierViewPage         = lazy(() => import('./pages/inventory/SupplierViewPage'))
const CustomersPage            = lazy(() => import('./pages/inventory/CustomersPage'))
const CustomerFormPage         = lazy(() => import('./pages/inventory/CustomerFormPage'))
const CustomerViewPage         = lazy(() => import('./pages/inventory/CustomerViewPage'))
const CategoriesPage           = lazy(() => import('./pages/inventory/CategoriesPage'))
const CategoryViewPage         = lazy(() => import('./pages/inventory/CategoryViewPage'))
const AttributeTypesPage       = lazy(() => import('./pages/inventory/AttributeTypesPage'))
const AttributesPage           = lazy(() => import('./pages/inventory/AttributesPage'))
const StoreTypesPage           = lazy(() => import('./pages/inventory/StoreTypesPage'))
const StoresPage               = lazy(() => import('./pages/inventory/StoresPage'))
const StoreFormPage            = lazy(() => import('./pages/inventory/StoreFormPage'))
const DriversPage              = lazy(() => import('./pages/inventory/DriversPage'))
const DriverFormPage           = lazy(() => import('./pages/inventory/DriverFormPage'))
const DriverViewPage           = lazy(() => import('./pages/inventory/DriverViewPage'))
const VehiclesPage             = lazy(() => import('./pages/inventory/VehiclesPage'))
const VehicleFormPage          = lazy(() => import('./pages/inventory/VehicleFormPage'))
const VehicleViewPage          = lazy(() => import('./pages/inventory/VehicleViewPage'))
const PurchaseRequestsPage     = lazy(() => import('./pages/inventory/PurchaseRequestsPage'))
const PurchaseRequestFormPage  = lazy(() => import('./pages/inventory/PurchaseRequestFormPage'))
const PurchaseOrdersPage       = lazy(() => import('./pages/inventory/PurchaseOrdersPage'))
const PurchaseOrderFormPage    = lazy(() => import('./pages/inventory/PurchaseOrderFormPage'))
const GoodsReceivedNotesPage   = lazy(() => import('./pages/inventory/GoodsReceivedNotesPage'))
const GoodsReceivedNoteFormPage = lazy(() => import('./pages/inventory/GoodsReceivedNoteFormPage'))
const PieceScanResultPage       = lazy(() => import('./pages/inventory/PieceScanResultPage'))
const PrintPieceLabelsPage      = lazy(() => import('./pages/inventory/PrintPieceLabelsPage'))
const CostingsPage              = lazy(() => import('./pages/inventory/CostingsPage'))
const CostingFormPage           = lazy(() => import('./pages/inventory/CostingFormPage'))
const SupplierPaymentsPage      = lazy(() => import('./pages/inventory/SupplierPaymentsPage'))
const SupplierPaymentFormPage   = lazy(() => import('./pages/inventory/SupplierPaymentFormPage'))
const SupplierCreditNotesPage   = lazy(() => import('./pages/inventory/SupplierCreditNotesPage'))
const PaymentModesPage          = lazy(() => import('./pages/inventory/PaymentModesPage'))
const SalesOrdersPage           = lazy(() => import('./pages/inventory/SalesOrdersPage'))
const SalesOrderFormPage        = lazy(() => import('./pages/inventory/SalesOrderFormPage'))
const SalesOrderViewPage        = lazy(() => import('./pages/inventory/SalesOrderViewPage'))
const DeliveryOrdersPage        = lazy(() => import('./pages/inventory/DeliveryOrdersPage'))
const DeliveryOrderFormPage     = lazy(() => import('./pages/inventory/DeliveryOrderFormPage'))
const DeliveryOrderViewPage     = lazy(() => import('./pages/inventory/DeliveryOrderViewPage'))
const InvoicesPage              = lazy(() => import('./pages/inventory/InvoicesPage'))
const InvoiceFormPage           = lazy(() => import('./pages/inventory/InvoiceFormPage'))
const InvoiceViewPage           = lazy(() => import('./pages/inventory/InvoiceViewPage'))
const CustomerReceiptsPage      = lazy(() => import('./pages/inventory/CustomerReceiptsPage'))
const CustomerReceiptFormPage   = lazy(() => import('./pages/inventory/CustomerReceiptFormPage'))
const CustomerCreditNotesPage   = lazy(() => import('./pages/inventory/CustomerCreditNotesPage'))
// Inventory Reports
const StockLevelsReport        = lazy(() => import('./pages/inventory/reports/StockLevelsReport'))
const StockMovementsReport     = lazy(() => import('./pages/inventory/reports/StockMovementsReport'))
const LowStockReport           = lazy(() => import('./pages/inventory/reports/LowStockReport'))
const StockValuationReport     = lazy(() => import('./pages/inventory/reports/StockValuationReport'))
const BatchExpiryReport        = lazy(() => import('./pages/inventory/reports/BatchExpiryReport'))
const PurchaseRequestsReport   = lazy(() => import('./pages/inventory/reports/PurchaseRequestsReport'))
const PurchaseOrdersReport     = lazy(() => import('./pages/inventory/reports/PurchaseOrdersReport'))
const OutstandingPOsReport     = lazy(() => import('./pages/inventory/reports/OutstandingPOsReport'))
const GrnReport                = lazy(() => import('./pages/inventory/reports/GrnReport'))
const SupplierSummaryReport    = lazy(() => import('./pages/inventory/reports/SupplierSummaryReport'))
const LandedCostsReport        = lazy(() => import('./pages/inventory/reports/LandedCostsReport'))
const BinCardReport            = lazy(() => import('./pages/inventory/reports/BinCardReport'))
const StockMovementSummaryReport = lazy(() => import('./pages/inventory/reports/StockMovementSummaryReport'))
const SalesByItemSummaryReport   = lazy(() => import('./pages/inventory/reports/SalesByItemSummaryReport'))
const SalesByItemDetailSummaryReport = lazy(() => import('./pages/inventory/reports/SalesByItemDetailSummaryReport'))
const SalesByCustomerDetailsReport = lazy(() => import('./pages/inventory/reports/SalesByCustomerDetailsReport'))
const OutstandingSummaryReport = lazy(() => import('./pages/inventory/reports/OutstandingSummaryReport'))
const SalesSummaryReport       = lazy(() => import('./pages/inventory/reports/SalesSummaryReport'))
const SupplierWiseGrnDetailsReport = lazy(() => import('./pages/inventory/reports/SupplierWiseGrnDetailsReport'))
const ItemSearchReport = lazy(() => import('./pages/inventory/reports/ItemSearchReport'))

const UserManagementPage    = lazy(() => import('./pages/admin/UserManagementPage'))
const RolesPage             = lazy(() => import('./pages/admin/RolesPage'))
const RoleFormPage          = lazy(() => import('./pages/admin/RoleFormPage'))

function PageLoader() {
  return (
    <div className="flex h-full items-center justify-center py-16 text-sm text-slate-400">
      Loading…
    </div>
  )
}

function Lazy({ component: C }) {
  // Key by path so routes sharing one component (e.g. .../create and .../:id/edit)
  // remount instead of reusing state across navigation — see product_code bug.
  const { pathname } = useLocation()
  return (
    <Suspense fallback={<PageLoader />}>
      <C key={pathname} />
    </Suspense>
  )
}

export default function App() {
  return (
    <Routes>
      {/* Public */}
      <Route path="/login" element={<LoginPage />} />

      {/* Standalone — scanned via phone camera, no desktop admin chrome */}
      <Route path="/inventory/pieces/:pieceCode" element={<Lazy component={PieceScanResultPage} />} />

      {/* Protected shell */}
      <Route element={<Layout />}>
        <Route path="/dashboard"    element={<Lazy component={DashboardPage} />} />
        <Route path="/admin/users"              element={<Lazy component={UserManagementPage} />} />
        <Route path="/admin/roles"              element={<Lazy component={RolesPage} />} />
        <Route path="/admin/roles/create"       element={<Lazy component={RoleFormPage} />} />
        <Route path="/admin/roles/:id/edit"     element={<Lazy component={RoleFormPage} />} />

        {/* Inventory module — only its own chunk is downloaded */}
        <Route path="/inventory" element={<Navigate to="/inventory/unit-categories" replace />} />
        <Route path="/inventory/unit-categories"              element={<Lazy component={UnitCategoriesPage} />} />
        <Route path="/inventory/unit-types"                   element={<Lazy component={UnitTypesPage} />} />
        <Route path="/inventory/unit-conversions"            element={<Lazy component={UnitConversionsPage} />} />
        <Route path="/inventory/products"                        element={<Lazy component={ProductsPage} />} />
        <Route path="/inventory/products/create"               element={<Lazy component={ProductFormPage} />} />
        <Route path="/inventory/products/:id"                  element={<Lazy component={ProductViewPage} />} />
        <Route path="/inventory/products/:id/edit"             element={<Lazy component={ProductFormPage} />} />
        <Route path="/inventory/sales-channels"                element={<Lazy component={SalesChannelsPage} />} />
        <Route path="/inventory/sales-channels/:id"            element={<Lazy component={SalesChannelViewPage} />} />
        <Route path="/inventory/industries"                    element={<Lazy component={IndustriesPage} />} />
        <Route path="/inventory/industries/:id"                element={<Lazy component={IndustryViewPage} />} />
        <Route path="/inventory/companies"                     element={<Lazy component={CompaniesPage} />} />
        <Route path="/inventory/companies/create"              element={<Lazy component={CompanyFormPage} />} />
        <Route path="/inventory/companies/:id"                 element={<Lazy component={CompanyViewPage} />} />
        <Route path="/inventory/companies/:id/edit"            element={<Lazy component={CompanyFormPage} />} />
        <Route path="/inventory/locations"                     element={<Lazy component={LocationsPage} />} />
        <Route path="/inventory/locations/create"              element={<Lazy component={LocationFormPage} />} />
        <Route path="/inventory/locations/:id"                 element={<Lazy component={LocationViewPage} />} />
        <Route path="/inventory/locations/:id/edit"            element={<Lazy component={LocationFormPage} />} />
        <Route path="/inventory/suppliers"                     element={<Lazy component={SuppliersPage} />} />
        <Route path="/inventory/suppliers/create"              element={<Lazy component={SupplierFormPage} />} />
        <Route path="/inventory/suppliers/:id"                 element={<Lazy component={SupplierViewPage} />} />
        <Route path="/inventory/suppliers/:id/edit"            element={<Lazy component={SupplierFormPage} />} />
        <Route path="/inventory/customers"                     element={<Lazy component={CustomersPage} />} />
        <Route path="/inventory/customers/create"              element={<Lazy component={CustomerFormPage} />} />
        <Route path="/inventory/customers/:id"                 element={<Lazy component={CustomerViewPage} />} />
        <Route path="/inventory/customers/:id/edit"            element={<Lazy component={CustomerFormPage} />} />
        <Route path="/inventory/categories"                    element={<Lazy component={CategoriesPage} />} />
        <Route path="/inventory/categories/:id"                element={<Lazy component={CategoryViewPage} />} />
        <Route path="/inventory/attribute-types"               element={<Lazy component={AttributeTypesPage} />} />
        <Route path="/inventory/attributes"                    element={<Lazy component={AttributesPage} />} />
        <Route path="/inventory/store-types"                   element={<Lazy component={StoreTypesPage} />} />
        <Route path="/inventory/stores"                        element={<Lazy component={StoresPage} />} />
        <Route path="/inventory/stores/create"                 element={<Lazy component={StoreFormPage} />} />
        <Route path="/inventory/stores/:id/edit"               element={<Lazy component={StoreFormPage} />} />
        <Route path="/inventory/drivers"                       element={<Lazy component={DriversPage} />} />
        <Route path="/inventory/drivers/create"                element={<Lazy component={DriverFormPage} />} />
        <Route path="/inventory/drivers/:id"                   element={<Lazy component={DriverViewPage} />} />
        <Route path="/inventory/drivers/:id/edit"              element={<Lazy component={DriverFormPage} />} />
        <Route path="/inventory/vehicles"                      element={<Lazy component={VehiclesPage} />} />
        <Route path="/inventory/vehicles/create"               element={<Lazy component={VehicleFormPage} />} />
        <Route path="/inventory/vehicles/:id"                  element={<Lazy component={VehicleViewPage} />} />
        <Route path="/inventory/vehicles/:id/edit"             element={<Lazy component={VehicleFormPage} />} />

        {/* ── Purchasing ── */}
        <Route path="/inventory/purchase-requests"             element={<Lazy component={PurchaseRequestsPage} />} />
        <Route path="/inventory/purchase-requests/create"      element={<Lazy component={PurchaseRequestFormPage} />} />
        <Route path="/inventory/purchase-requests/:id"         element={<Lazy component={PurchaseRequestFormPage} />} />
        <Route path="/inventory/purchase-requests/:id/edit"    element={<Lazy component={PurchaseRequestFormPage} />} />
        <Route path="/inventory/purchase-orders"               element={<Lazy component={PurchaseOrdersPage} />} />
        <Route path="/inventory/purchase-orders/create"        element={<Lazy component={PurchaseOrderFormPage} />} />
        <Route path="/inventory/purchase-orders/:id"           element={<Lazy component={PurchaseOrderFormPage} />} />
        <Route path="/inventory/purchase-orders/:id/edit"      element={<Lazy component={PurchaseOrderFormPage} />} />
        <Route path="/inventory/sales-orders"                  element={<Lazy component={SalesOrdersPage} />} />
        <Route path="/inventory/sales-orders/create"           element={<Lazy component={SalesOrderFormPage} />} />
        <Route path="/inventory/sales-orders/:id"              element={<Lazy component={SalesOrderViewPage} />} />
        <Route path="/inventory/sales-orders/:id/edit"         element={<Lazy component={SalesOrderFormPage} />} />
        <Route path="/inventory/delivery-orders"               element={<Lazy component={DeliveryOrdersPage} />} />
        <Route path="/inventory/delivery-orders/create"        element={<Lazy component={DeliveryOrderFormPage} />} />
        <Route path="/inventory/delivery-orders/:id"           element={<Lazy component={DeliveryOrderViewPage} />} />
        <Route path="/inventory/delivery-orders/:id/edit"      element={<Lazy component={DeliveryOrderFormPage} />} />
        <Route path="/inventory/invoices"                      element={<Lazy component={InvoicesPage} />} />
        <Route path="/inventory/invoices/create"               element={<Lazy component={InvoiceFormPage} />} />
        <Route path="/inventory/invoices/:id"                  element={<Lazy component={InvoiceViewPage} />} />
        <Route path="/inventory/invoices/:id/edit"             element={<Lazy component={InvoiceFormPage} />} />
        <Route path="/inventory/customer-receipts"             element={<Lazy component={CustomerReceiptsPage} />} />
        <Route path="/inventory/customer-receipts/create"      element={<Lazy component={CustomerReceiptFormPage} />} />
        <Route path="/inventory/customer-receipts/:id/edit"    element={<Lazy component={CustomerReceiptFormPage} />} />
        <Route path="/inventory/customer-credit-notes"         element={<Lazy component={CustomerCreditNotesPage} />} />
        <Route path="/inventory/goods-received-notes"          element={<Lazy component={GoodsReceivedNotesPage} />} />
        <Route path="/inventory/goods-received-notes/create"   element={<Lazy component={GoodsReceivedNoteFormPage} />} />
        <Route path="/inventory/goods-received-notes/:id"      element={<Lazy component={GoodsReceivedNoteFormPage} />} />
        <Route path="/inventory/goods-received-notes/:id/edit" element={<Lazy component={GoodsReceivedNoteFormPage} />} />
        <Route path="/inventory/piece-labels"                  element={<Lazy component={PrintPieceLabelsPage} />} />
        <Route path="/inventory/costings"                      element={<Lazy component={CostingsPage} />} />
        <Route path="/inventory/costings/create"               element={<Lazy component={CostingFormPage} />} />
        <Route path="/inventory/costings/:id"                  element={<Lazy component={CostingFormPage} />} />
        <Route path="/inventory/costings/:id/edit"             element={<Lazy component={CostingFormPage} />} />
        <Route path="/inventory/supplier-payments"             element={<Lazy component={SupplierPaymentsPage} />} />
        <Route path="/inventory/supplier-payments/create"      element={<Lazy component={SupplierPaymentFormPage} />} />
        <Route path="/inventory/supplier-payments/:id/edit"    element={<Lazy component={SupplierPaymentFormPage} />} />
        <Route path="/inventory/supplier-credit-notes"         element={<Lazy component={SupplierCreditNotesPage} />} />
        <Route path="/inventory/payment-modes"                 element={<Lazy component={PaymentModesPage} />} />

        {/* ── Inventory Reports ── */}
        <Route path="/inventory/reports/stock-levels"      element={<Lazy component={StockLevelsReport} />} />
        <Route path="/inventory/reports/stock-movements"   element={<Lazy component={StockMovementsReport} />} />
        <Route path="/inventory/reports/low-stock"         element={<Lazy component={LowStockReport} />} />
        <Route path="/inventory/reports/stock-valuation"   element={<Lazy component={StockValuationReport} />} />
        <Route path="/inventory/reports/batch-expiry"      element={<Lazy component={BatchExpiryReport} />} />
        <Route path="/inventory/reports/purchase-requests" element={<Lazy component={PurchaseRequestsReport} />} />
        <Route path="/inventory/reports/purchase-orders"   element={<Lazy component={PurchaseOrdersReport} />} />
        <Route path="/inventory/reports/outstanding-pos"   element={<Lazy component={OutstandingPOsReport} />} />
        <Route path="/inventory/reports/grn"               element={<Lazy component={GrnReport} />} />
        <Route path="/inventory/reports/supplier-summary"  element={<Lazy component={SupplierSummaryReport} />} />
        <Route path="/inventory/reports/landed-costs"      element={<Lazy component={LandedCostsReport} />} />
        <Route path="/inventory/reports/bin-card"          element={<Lazy component={BinCardReport} />} />
        <Route path="/inventory/reports/movement-summary"  element={<Lazy component={StockMovementSummaryReport} />} />
        <Route path="/inventory/reports/sales-by-item-summary" element={<Lazy component={SalesByItemSummaryReport} />} />
        <Route path="/inventory/reports/sales-by-item-detail-summary" element={<Lazy component={SalesByItemDetailSummaryReport} />} />
        <Route path="/inventory/reports/sales-by-customer-details" element={<Lazy component={SalesByCustomerDetailsReport} />} />
        <Route path="/inventory/reports/outstanding-summary" element={<Lazy component={OutstandingSummaryReport} />} />
        <Route path="/inventory/reports/sales-summary" element={<Lazy component={SalesSummaryReport} />} />
        <Route path="/inventory/reports/supplier-wise-grn-details" element={<Lazy component={SupplierWiseGrnDetailsReport} />} />
        <Route path="/inventory/reports/item-search" element={<Lazy component={ItemSearchReport} />} />
      </Route>

      {/* Fallback */}
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  )
}
